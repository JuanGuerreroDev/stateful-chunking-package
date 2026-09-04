<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Drivers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Tests\TestCase;

class RedisDriverFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension is not loaded.');
        }

        try {
            $r = new \Redis();
            $r->connect('127.0.0.1', 6379, 1.5);
            $r->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis server is not reachable: ' . $e->getMessage());
        }

        config()->set('cache.default', 'redis');
        config()->set('stateful-chunking.cache_store', 'redis');
        config()->set('database.redis.client', 'phpredis');
        config()->set('database.redis.default', [
            'url' => null,
            'host' => '127.0.0.1',
            'password' => null,
            'port' => 6379,
            'database' => 0,
        ]);

        Storage::fake('local');
    }

    public function test_redis_cache_driver_persists_session_and_completes_upload(): void
    {
        $chunk0Data = "REDIS DRIVER TEST CHUNK 0 - HIGH PERFORMANCE IN-MEMORY CACHE.";
        $chunk1Data = "REDIS DRIVER TEST CHUNK 1 - ATOMIC DISTRIBUTED LOCKS IN REDIS.";
        $fullContent = $chunk0Data . $chunk1Data;

        $chunk0Hash = hash('sha256', $chunk0Data);
        $chunk1Hash = hash('sha256', $chunk1Data);
        $totalHash = hash('sha256', $fullContent);
        $fingerprint = 'redis_driver_fp_' . time();

        // 1. Initiate upload session
        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'redis_driver_test.txt',
            'file_size' => strlen($fullContent),
            'total_chunks' => 2,
            'total_hash' => $totalHash,
            'fingerprint' => $fingerprint,
        ]);

        $initiateResponse->assertStatus(201);
        $sessionId = (string) $initiateResponse->json('data.session_id');
        $this->assertNotEmpty($sessionId);

        // Assert session exists in Redis cache store
        $redisSession = Cache::store('redis')->get("chunk_session:{$sessionId}");
        $this->assertIsArray($redisSession);
        $this->assertEquals('redis_driver_test.txt', $redisSession['file_name']);

        // 2. Upload Chunk 0
        $file0 = UploadedFile::fake()->createWithContent('chunk_0.tmp', $chunk0Data);
        $upload0Response = $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => $chunk0Hash,
        ], [], ['file' => $file0]);

        $upload0Response->assertStatus(200);

        // 3. Status check from Redis cache store
        $statusResponse = $this->getJson("/api/chunks/status/{$sessionId}");
        $statusResponse->assertStatus(200);
        $this->assertEquals('completed', $statusResponse->json('data.chunks_map.0'));
        $this->assertEquals('pending', $statusResponse->json('data.chunks_map.1'));

        // 4. Upload Chunk 1
        $file1 = UploadedFile::fake()->createWithContent('chunk_1.tmp', $chunk1Data);
        $upload1Response = $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 1,
            'chunk_hash' => $chunk1Hash,
        ], [], ['file' => $file1]);

        $upload1Response->assertStatus(200);
        $this->assertEquals('completed', $upload1Response->json('data.status'));

        // 5. Complete and reassemble
        $completeResponse = $this->postJson('/api/chunks/complete', [
            'session_id' => $sessionId,
        ]);
        $completeResponse->assertStatus(200);
        $this->assertTrue($completeResponse->json('data.verified'));
        $this->assertNotEmpty($completeResponse->json('data.upload_token'));
    }

    public function test_redis_cache_driver_purges_state_on_cancellation(): void
    {
        $fingerprint = 'redis_cancel_fp_' . time();
        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'to_cancel_redis.txt',
            'file_size' => 1024,
            'total_chunks' => 2,
            'total_hash' => hash('sha256', 'dummy'),
            'fingerprint' => $fingerprint,
        ]);

        $initiateResponse->assertStatus(201);
        $sessionId = (string) $initiateResponse->json('data.session_id');

        // Verify key exists in Redis store
        $this->assertNotNull(Cache::store('redis')->get("chunk_session:{$sessionId}"));

        // Cancel session
        $cancelResponse = $this->deleteJson("/api/chunks/cancel/{$sessionId}");
        $cancelResponse->assertStatus(200);

        // Verify key is purged from Redis
        $this->assertNull(Cache::store('redis')->get("chunk_session:{$sessionId}"));

        // Verify status returns 404
        $statusResponse = $this->getJson("/api/chunks/status/{$sessionId}");
        $statusResponse->assertStatus(404);
    }
}
