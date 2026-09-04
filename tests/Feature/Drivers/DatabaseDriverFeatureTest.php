<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Drivers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Tests\TestCase;

class DatabaseDriverFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The pdo_sqlite PHP extension is required for DatabaseDriverFeatureTest.');
        }

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        config()->set('cache.default', 'database');
        config()->set('cache.stores.database', [
            'driver' => 'database',
            'table' => 'cache',
            'lock_table' => 'cache_locks',
            'connection' => 'sqlite',
        ]);
        config()->set('stateful-chunking.cache_store', 'database');

        Storage::fake('local');

        // Create Laravel standard cache and cache_locks schema in sqlite memory
        Schema::create('cache', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function ($table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    public function test_database_cache_driver_persists_session_in_sql_table_and_completes_upload(): void
    {
        $chunk0Data = "DATABASE DRIVER TEST CHUNK 0 - RELATIONAL SQL PERSISTENCE.";
        $chunk1Data = "DATABASE DRIVER TEST CHUNK 1 - ATOMIC LOCKING IN SQLITE.";
        $fullContent = $chunk0Data . $chunk1Data;

        $chunk0Hash = hash('sha256', $chunk0Data);
        $chunk1Hash = hash('sha256', $chunk1Data);
        $totalHash = hash('sha256', $fullContent);

        // 1. Initiate upload session
        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'database_driver_test.txt',
            'file_size' => strlen($fullContent),
            'total_chunks' => 2,
            'total_hash' => $totalHash,
            'fingerprint' => 'db_driver_fp_' . time(),
        ]);

        $initiateResponse->assertStatus(201);
        $sessionId = (string) $initiateResponse->json('data.session_id');
        $this->assertNotEmpty($sessionId);

        // Assert direct SQL persistence in the cache table
        $dbRecords = DB::table('cache')->get();
        $this->assertTrue($dbRecords->isNotEmpty());
        $sessionRecord = DB::table('cache')->where('key', 'like', "%{$sessionId}%")->first();
        $this->assertNotNull($sessionRecord);

        // 2. Upload Chunk 0
        $file0 = UploadedFile::fake()->createWithContent('chunk_0.tmp', $chunk0Data);
        $upload0Response = $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => $chunk0Hash,
        ], [], ['file' => $file0]);

        $upload0Response->assertStatus(200);

        // 3. Status check from DB cache store
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

    public function test_database_cache_driver_deletes_rows_on_cancellation(): void
    {
        $fingerprint = 'db_cancel_fp_' . time();
        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'to_cancel_db.txt',
            'file_size' => 1024,
            'total_chunks' => 2,
            'total_hash' => hash('sha256', 'dummy'),
            'fingerprint' => $fingerprint,
        ]);

        $initiateResponse->assertStatus(201);
        $sessionId = (string) $initiateResponse->json('data.session_id');

        // Verify DB row exists before cancel
        $this->assertNotNull(DB::table('cache')->where('key', 'like', "%{$sessionId}%")->first());

        // Cancel session
        $cancelResponse = $this->deleteJson("/api/chunks/cancel/{$sessionId}");
        $cancelResponse->assertStatus(200);

        // Verify row deleted from DB
        $this->assertNull(DB::table('cache')->where('key', 'like', "%{$sessionId}%")->first());

        // Verify status endpoint returns 404
        $statusResponse = $this->getJson("/api/chunks/status/{$sessionId}");
        $statusResponse->assertStatus(404);
    }
}
