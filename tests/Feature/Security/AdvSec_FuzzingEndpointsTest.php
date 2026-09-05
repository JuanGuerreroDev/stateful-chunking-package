<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * OFFENSIVE SECURITY TESTS: Automated Fuzzing against all 5 endpoints
 *
 * Security Invariant: No input should produce an unhandled exception (HTTP 500).
 * All error responses must be controlled (4xx).
 */
class AdvSec_FuzzingEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('stateful-chunking.rate_limits.initiate', 100000);
        Config::set('stateful-chunking.rate_limits.upload', 100000);
        RateLimiter::clear('stateful-chunking-initiate');
        RateLimiter::clear('stateful-chunking-upload');
    }

    private function assertNoUnhandledException(int $status, string $context): void
    {
        $this->assertNotEquals(500, $status, "Fuzzing produced HTTP 500 for: {$context}");
        $this->assertLessThan(500, $status, "Fuzzing produced >= 500 for: {$context}");
    }

    // -------------------------------------------------------------------------
    // POST /api/chunks/initiate
    // -------------------------------------------------------------------------

    public function test_fuzz_initiate_endpoint_rejects_all_garbage_without_crashing(): void
    {
        $validHash = hash('sha256', 'x');

        $cases = [
            'long file_name (10k)'      => ['file_name' => str_repeat('a', 10000) . '.bin', 'file_size' => 100, 'total_chunks' => 1, 'total_hash' => $validHash],
            'unicode CJK filename'       => ['file_name' => '文件名称测试.bin', 'file_size' => 100, 'total_chunks' => 1, 'total_hash' => $validHash],
            'unicode emoji filename'     => ['file_name' => '🔥upload🔥.jpg', 'file_size' => 100, 'total_chunks' => 1, 'total_hash' => $validHash],
            'null byte in filename'      => ['file_name' => "malware\x00.jpg", 'file_size' => 100, 'total_chunks' => 1, 'total_hash' => $validHash],
            'double encoded traversal'   => ['file_name' => '%2e%2e%2fetc%2fpasswd.jpg', 'file_size' => 100, 'total_chunks' => 1, 'total_hash' => $validHash],
            'negative file_size'         => ['file_name' => 'valid.jpg', 'file_size' => -999, 'total_chunks' => 1, 'total_hash' => $validHash],
            'zero file_size'             => ['file_name' => 'valid.jpg', 'file_size' => 0, 'total_chunks' => 1, 'total_hash' => $validHash],
            'PHP_INT_MAX total_chunks'   => ['file_name' => 'valid.jpg', 'file_size' => 100, 'total_chunks' => PHP_INT_MAX, 'total_hash' => $validHash],
            'negative total_chunks'      => ['file_name' => 'valid.jpg', 'file_size' => 100, 'total_chunks' => -1, 'total_hash' => $validHash],
            'short hash (32 chars)'      => ['file_name' => 'valid.jpg', 'file_size' => 100, 'total_chunks' => 1, 'total_hash' => str_repeat('a', 32)],
            'empty total_hash'           => ['file_name' => 'valid.jpg', 'file_size' => 100, 'total_chunks' => 1, 'total_hash' => ''],
            'array as file_name'         => ['file_name' => ['../../etc/passwd'], 'file_size' => 100, 'total_chunks' => 1, 'total_hash' => $validHash],
            'all null fields'            => ['file_name' => null, 'file_size' => null, 'total_chunks' => null, 'total_hash' => null],
            'empty payload'              => [],
            'fingerprint 10k chars'      => ['file_name' => 'valid.jpg', 'file_size' => 100, 'total_chunks' => 1, 'total_hash' => $validHash, 'fingerprint' => str_repeat('x', 10000)],
            'file_size as string'        => ['file_name' => 'valid.jpg', 'file_size' => 'one hundred', 'total_chunks' => 1, 'total_hash' => $validHash],
            'PHP_INT_MAX file_size'      => ['file_name' => 'valid.jpg', 'file_size' => PHP_INT_MAX, 'total_chunks' => 1, 'total_hash' => $validHash],
            'boolean total_chunks'       => ['file_name' => 'valid.jpg', 'file_size' => 100, 'total_chunks' => true, 'total_hash' => $validHash],
        ];

        foreach ($cases as $label => $payload) {
            $response = $this->postJson('/api/chunks/initiate', $payload);
            $this->assertNoUnhandledException($response->status(), "initiate: {$label}");
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/chunks/upload
    // -------------------------------------------------------------------------

    public function test_fuzz_upload_endpoint_rejects_all_garbage_without_crashing(): void
    {
        $content   = str_repeat('x', 32);
        $validHash = hash('sha256', $content);
        $validUuid = '00000000-0000-0000-0000-000000000001';

        $cases = [
            'non-uuid session_id (1k)'    => ['session_id' => str_repeat('x', 1000), 'chunk_index' => 0, 'chunk_hash' => $validHash],
            'negative chunk_index'         => ['session_id' => $validUuid, 'chunk_index' => -1, 'chunk_hash' => $validHash],
            'PHP_INT_MAX chunk_index'      => ['session_id' => $validUuid, 'chunk_index' => PHP_INT_MAX, 'chunk_hash' => $validHash],
            'short chunk_hash (32 chars)'  => ['session_id' => $validUuid, 'chunk_index' => 0, 'chunk_hash' => str_repeat('a', 32)],
            'empty chunk_hash'             => ['session_id' => $validUuid, 'chunk_index' => 0, 'chunk_hash' => ''],
            'null session_id'              => ['session_id' => null, 'chunk_index' => 0, 'chunk_hash' => $validHash],
            'array chunk_hash'             => ['session_id' => $validUuid, 'chunk_index' => 0, 'chunk_hash' => ['malicious']],
            'empty payload'                => [],
            'non-hex chunk_hash'           => ['session_id' => $validUuid, 'chunk_index' => 0, 'chunk_hash' => str_repeat('G', 64)],
        ];

        foreach ($cases as $label => $params) {
            $file     = UploadedFile::fake()->createWithContent('chunk.tmp', $content);
            $response = $this->call('POST', '/api/chunks/upload', $params, [], ['file' => $file], ['HTTP_ACCEPT' => 'application/json']);
            $this->assertNoUnhandledException($response->status(), "upload: {$label}");
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/chunks/status/{sessionId}
    // -------------------------------------------------------------------------

    public function test_fuzz_status_endpoint_rejects_all_garbage_without_crashing(): void
    {
        $cases = [
            'empty string'            => '',
            'long string (500 chars)' => str_repeat('a', 500),
            'path traversal'          => '../../../etc/passwd',
            'null byte'               => "valid\x00injection",
            'SQL injection'           => "' OR 1=1 --",
            'XSS payload'             => '<script>alert(1)</script>',
            'unicode'                 => '你好世界',
            'spaces'                  => '   ',
            'special chars'           => '!@#$%^&*()',
            'double encoded slash'    => '%2F..%2F..%2Fetc%2Fpasswd',
            'newline injection'       => "valid\r\nX-Injected: header",
        ];

        foreach ($cases as $label => $sessionId) {
            $encoded  = urlencode(substr($sessionId, 0, 100));
            $response = $this->getJson("/api/chunks/status/{$encoded}");
            $this->assertNoUnhandledException($response->status(), "status: {$label}");
        }
    }

    // -------------------------------------------------------------------------
    // DELETE /api/chunks/cancel/{sessionId}
    // -------------------------------------------------------------------------

    public function test_fuzz_cancel_endpoint_rejects_all_garbage_without_crashing(): void
    {
        $cases = [
            'empty string'            => '',
            'long string (500 chars)' => str_repeat('b', 500),
            'path traversal'          => '../../../etc/shadow',
            'SQL injection'           => "'; DROP TABLE sessions;--",
            'XSS payload'             => '"><svg onload=alert(1)>',
            'null byte'               => "session\x00hack",
            'newline injection'       => "id\r\nCache-Control: no-store",
            'unicode'                 => '안녕하세요-session',
        ];

        foreach ($cases as $label => $sessionId) {
            $encoded  = urlencode(substr($sessionId, 0, 100));
            $response = $this->deleteJson("/api/chunks/cancel/{$encoded}");
            $this->assertNoUnhandledException($response->status(), "cancel: {$label}");
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/chunks/complete
    // -------------------------------------------------------------------------

    public function test_fuzz_complete_endpoint_rejects_all_garbage_without_crashing(): void
    {
        $cases = [
            'valid UUID (no session)'    => ['session_id' => '00000000-0000-0000-0000-000000000001'],
            'long session_id (1k chars)' => ['session_id' => str_repeat('x', 1000)],
            'empty session_id'           => ['session_id' => ''],
            'null session_id'            => ['session_id' => null],
            'missing session_id'         => [],
            'array session_id'           => ['session_id' => ['malicious']],
            'SQL injection'              => ['session_id' => "' OR '1'='1"],
            'path traversal'             => ['session_id' => '../../etc/passwd'],
            'null byte'                  => ['session_id' => "valid\x00injection"],
            'unicode'                    => ['session_id' => '文件名称测试-session'],
            'newline injection'          => ['session_id' => "valid\r\nX-Injected: header"],
            'boolean'                    => ['session_id' => true],
            'integer'                    => ['session_id' => 12345],
            'PHP_INT_MAX'                => ['session_id' => PHP_INT_MAX],
        ];

        foreach ($cases as $label => $payload) {
            $response = $this->postJson('/api/chunks/complete', $payload);
            $this->assertNoUnhandledException($response->status(), "complete: {$label}");
        }
    }

    // -------------------------------------------------------------------------
    // All 5 endpoints: random binary garbage
    // -------------------------------------------------------------------------

    public function test_all_endpoints_handle_random_garbage_gracefully(): void
    {
        $garbageInputs = [
            "\x00\x01\x02\x03\x04\x05",
            str_repeat("\xFF", 50),
            "SELECT * FROM sessions WHERE 1=1; DROP TABLE sessions;--",
            "<img src=x onerror=alert(1)>",
            str_repeat("𝕳𝖊𝖑𝖑𝖔", 20),
        ];

        $validHash = hash('sha256', 'x');

        foreach ($garbageInputs as $garbage) {
            $r = $this->postJson('/api/chunks/initiate', ['file_name' => $garbage, 'file_size' => 100, 'total_chunks' => 1, 'total_hash' => $garbage]);
            $this->assertNoUnhandledException($r->status(), 'initiate random garbage');

            $r = $this->postJson('/api/chunks/complete', ['session_id' => $garbage]);
            $this->assertNoUnhandledException($r->status(), 'complete random garbage');

            $encoded = urlencode(substr($garbage, 0, 50));
            $r       = $this->getJson("/api/chunks/status/{$encoded}");
            $this->assertNoUnhandledException($r->status(), 'status random garbage');

            $r = $this->deleteJson("/api/chunks/cancel/{$encoded}");
            $this->assertNoUnhandledException($r->status(), 'cancel random garbage');
        }

        $this->addToAssertionCount(1);
    }
}
