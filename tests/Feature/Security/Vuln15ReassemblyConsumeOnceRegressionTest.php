<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunkingUpload\Tests\TestCase;

/**
 * VULN-15 REGRESSION TEST: reassembly is consume-once (LIVE-002).
 *
 * Reassembly runs under the session's exclusive lock and deletes the session on
 * success. A second /complete for the same session therefore finds nothing to
 * reassemble and returns 404, rather than reassembling and minting a second
 * upload token. The lock guarantees this holds even for concurrent callers; this
 * test pins the sequential, deterministic half of that contract.
 */
class Vuln15ReassemblyConsumeOnceRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('stateful-chunking-upload.rate_limits.enabled', false);
        foreach (['initiate', 'upload', 'complete'] as $op) {
            RateLimiter::clear('stateful-chunking-upload-'.$op);
        }
    }

    public function test_second_complete_after_success_returns_404(): void
    {
        $content = str_repeat('X', 512);
        $hash = hash('sha256', $content);

        $init = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'once.bin',
            'file_size' => strlen($content),
            'total_chunks' => 1,
            'total_hash' => $hash,
            'fingerprint' => 'once_'.uniqid(),
        ]);
        $init->assertStatus(201);
        $sessionId = (string) $init->json('data.session_id');

        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', $content);
        $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => $hash,
        ], [], ['file' => $file], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(200);

        // First completion succeeds and yields a token.
        $first = $this->postJson('/api/chunks/complete', ['session_id' => $sessionId]);
        $first->assertStatus(200);
        $this->assertNotEmpty($first->json('data.upload_token'));

        // Second completion finds the session consumed -> 404, no second token.
        $second = $this->postJson('/api/chunks/complete', ['session_id' => $sessionId]);
        $second->assertStatus(404);
        $this->assertNull($second->json('data.upload_token'));
    }
}
