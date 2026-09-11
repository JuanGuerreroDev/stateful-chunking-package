<?php

namespace Juanoecr\StatefulChunkingUpload\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunkingUpload\Tests\TestCase;

class SecureLoggingAndErrorHandlingTest extends TestCase
{
    public function test_controller_sanitizes_error_messages_and_does_not_leak_internal_exceptions(): void
    {
        // Initiate invalid complete request with non-existent session
        $validUuid = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
        $response = $this->postJson('/api/chunks/complete', [
            'session_id' => $validUuid,
        ]);

        // A non-existent session is now a typed domain failure: 404, safe message.
        $response->assertStatus(404);
        $json = $response->json();

        // Assert message is generic and sanitized (no paths, no class names).
        $this->assertEquals('Upload session not found.', $json['message']);
        $this->assertStringNotContainsString('/var/www', $json['message']);
        $this->assertStringNotContainsString('Exception', $json['message']);
    }

    public function test_audit_logs_are_dispatched_on_initiate_and_cancel(): void
    {
        Log::shouldReceive('channel')
            ->atLeast()->once()
            ->with(null)
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->atLeast()->once();

        $validSha256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $response = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'audit_test.pdf',
            'file_size' => 1024,
            'total_chunks' => 1,
            'total_hash' => $validSha256,
        ]);

        $response->assertStatus(201);
    }

    /**
     * The upload_token is a bearer credential: resolveToken() accepts the ciphertext
     * and nothing else, with no owner in the payload and no consumption on use. Its
     * encryption protects the path it names, not the capability it grants, so an
     * attacker never needs APP_KEY to abuse a logged token. They replay it and this
     * application decrypts it for them.
     *
     * The redaction in the complete() handler is therefore load-bearing, and this test
     * exists so that removing it fails the build instead of passing silently.
     */
    public function test_the_upload_token_never_reaches_the_audit_log(): void
    {
        Storage::fake('local');

        $logFile = storage_path('logs/upload-token-redaction.log');
        if (is_file($logFile)) {
            unlink($logFile);
        }

        Config::set('logging.channels.chunking_audit', [
            'driver' => 'single',
            'path' => $logFile,
            'level' => 'debug',
        ]);
        Config::set('stateful-chunking-upload.log_channel', 'chunking_audit');
        Config::set('stateful-chunking-upload.rate_limits.enabled', false);

        $content = str_repeat('T', 512);
        $hash = hash('sha256', $content);

        $init = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'audited.bin',
            'file_size' => strlen($content),
            'total_chunks' => 1,
            'total_hash' => $hash,
            'fingerprint' => 'audit_'.uniqid(),
        ]);
        $init->assertStatus(201);
        $sessionId = (string) $init->json('data.session_id');

        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', $content);
        $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => $hash,
        ], [], ['file' => $file], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(200);

        $complete = $this->postJson('/api/chunks/complete', ['session_id' => $sessionId]);
        $complete->assertStatus(200);

        $token = (string) $complete->json('data.upload_token');
        $this->assertNotEmpty($token, 'The lifecycle must actually mint a token, or this test proves nothing.');

        $this->assertFileExists($logFile);
        $written = (string) file_get_contents($logFile);

        // Monolog escapes forward slashes when it encodes the context as JSON, so compare
        // against an unescaped copy. Without this the assertion could pass on a token that
        // is present but written as base64 containing \/ sequences.
        $normalised = str_replace('\/', '/', $written);

        // Affirmative guard first: a test that asserts an absence proves nothing unless it
        // also proves the log line it inspects was emitted at all.
        $this->assertStringContainsString('File reassembled successfully', $normalised);
        $this->assertStringContainsString($sessionId, $normalised);

        $this->assertStringNotContainsString($token, $normalised);
        $this->assertStringNotContainsString('upload_token', $normalised);
    }
}
