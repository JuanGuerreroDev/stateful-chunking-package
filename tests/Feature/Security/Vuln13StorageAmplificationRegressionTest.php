<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * VULN-13 REGRESSION TEST: Storage-amplification DoS via unbounded total_chunks.
 *
 * A client could declare a tiny file_size while claiming up to max_total_chunks,
 * then stage oversized chunks (chunk_size x 1.1 each) far exceeding both the
 * declared file_size and max_file_size_bytes, because those only validate the
 * *declared* number, never the bytes actually written to disk.
 *
 * Security invariant: total_chunks MUST be bounded above by the chunk count that
 * the declared file_size implies (ceil(file_size / chunk_size) + 1 rounding slack),
 * capped by max_total_chunks.
 */
class Vuln13StorageAmplificationRegressionTest extends TestCase
{
    private const VALID_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('stateful-chunking.rate_limits.initiate', 1000);
        RateLimiter::clear('stateful-chunking-initiate');
    }

    public function test_tiny_file_cannot_claim_the_maximum_chunk_count(): void
    {
        $response = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'tiny.bin',
            'file_size' => 1,          // 1 byte declared
            'total_chunks' => 10000,      // but claims the maximum
            'total_hash' => self::VALID_SHA256,
            'fingerprint' => 'amp_max_'.uniqid(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('total_chunks');
    }

    public function test_chunk_count_moderately_above_file_size_is_rejected(): void
    {
        $chunkSize = (int) config('stateful-chunking.chunk_size_bytes', 2097152);
        $fileSize = 4 * $chunkSize;         // implies exactly 4 chunks
        $inflatedChunks = 50;               // grossly more than needed

        $response = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'inflated.bin',
            'file_size' => $fileSize,
            'total_chunks' => $inflatedChunks,
            'total_hash' => self::VALID_SHA256,
            'fingerprint' => 'amp_inflated_'.uniqid(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('total_chunks');
    }

    public function test_exact_chunk_count_for_file_size_is_accepted(): void
    {
        $chunkSize = (int) config('stateful-chunking.chunk_size_bytes', 2097152);
        $fileSize = 4 * $chunkSize;         // implies exactly 4 chunks

        $response = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'exact.bin',
            'file_size' => $fileSize,
            'total_chunks' => 4,
            'total_hash' => self::VALID_SHA256,
            'fingerprint' => 'amp_exact_'.uniqid(),
        ]);

        $response->assertStatus(201);
        $this->assertSame(4, $response->json('data.total_chunks'));
    }

    public function test_rounding_slack_of_one_extra_chunk_is_allowed(): void
    {
        $chunkSize = (int) config('stateful-chunking.chunk_size_bytes', 2097152);
        $fileSize = 4 * $chunkSize;         // implies 4 chunks; +1 slack allows 5

        $response = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'slack.bin',
            'file_size' => $fileSize,
            'total_chunks' => 5,
            'total_hash' => self::VALID_SHA256,
            'fingerprint' => 'amp_slack_'.uniqid(),
        ]);

        $response->assertStatus(201);
    }
}
