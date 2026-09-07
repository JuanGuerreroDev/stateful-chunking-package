<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * VULN-14 REGRESSION TEST: cumulative byte budget (defense-in-depth for LIVE-001).
 *
 * Even when total_chunks is within bounds and each chunk is under the per-chunk
 * size cap, the sum of chunk bytes must not exceed the budget the declared
 * file_size allows (file_size + one chunk of slack). This stops an attacker from
 * understating file_size and then filling the allowed chunk slots with oversized
 * payloads.
 */
class Vuln14ByteBudgetRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Small chunk size keeps the test payloads tiny.
        Config::set('stateful-chunking.chunk_size_bytes', 1024);
        Config::set('stateful-chunking.rate_limits.enabled', false);
        foreach (['initiate', 'upload'] as $op) {
            RateLimiter::clear('stateful-chunking-'.$op);
        }
    }

    private function uploadChunk(string $sessionId, int $index, string $content): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent("chunk_{$index}.tmp", $content);

        return $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => $index,
            'chunk_hash' => hash('sha256', $content),
        ], [], ['file' => $file], ['HTTP_ACCEPT' => 'application/json']);
    }

    public function test_cumulative_oversized_chunks_are_rejected_beyond_budget(): void
    {
        // Declare a 1024-byte file. Budget = file_size + chunk_size = 2048 bytes.
        // total_chunks upper bound is ceil(1024/1024)+1 = 2, so 2 slots are allowed.
        $init = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'budget.bin',
            'file_size' => 1024,
            'total_chunks' => 2,
            'total_hash' => hash('sha256', 'total'),
            'fingerprint' => 'budget_'.uniqid(),
        ]);
        $init->assertStatus(201);
        $sessionId = (string) $init->json('data.session_id');

        // First 1100-byte chunk fits under the 2048 budget.
        $this->uploadChunk($sessionId, 0, str_repeat('A', 1100))->assertStatus(200);

        // Second 1100-byte chunk would push cumulative to 2200 > 2048 -> rejected 413.
        $this->uploadChunk($sessionId, 1, str_repeat('B', 1100))->assertStatus(413);
    }

    public function test_uploads_within_budget_are_accepted(): void
    {
        $init = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'ok.bin',
            'file_size' => 2048,        // budget = 2048 + 1024 = 3072
            'total_chunks' => 2,
            'total_hash' => hash('sha256', 'total'),
            'fingerprint' => 'budget_ok_'.uniqid(),
        ]);
        $init->assertStatus(201);
        $sessionId = (string) $init->json('data.session_id');

        $this->uploadChunk($sessionId, 0, str_repeat('A', 1000))->assertStatus(200);
        $this->uploadChunk($sessionId, 1, str_repeat('B', 1000))->assertStatus(200);
    }
}
