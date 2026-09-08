<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Juanoecr\StatefulChunking\Tests\Support\Snapshot;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * Freezes the exact JSON contract of every endpoint's success envelope.
 *
 * These snapshots are the byte-for-byte counterpart to ResponseEnvelopeTest's
 * targeted PII/path checks: they assert the response is EXACTLY the recorded
 * shape — not one field more. A new field leaking out of ChunkSession, a dropped
 * key, or a reordered payload all fail here. Inputs are fixed so hashes are
 * deterministic; ids/timestamps/tokens are masked by {@see Snapshot}.
 *
 * Regenerate after an intentional contract change: `UPDATE_SNAPSHOTS=1 pest`.
 */
class ContractSnapshotTest extends TestCase
{
    private const CONTENT = 'SNAPSHOT-CONTRACT-FIXED-CONTENT';

    private const FILE_NAME = 'contract.bin';

    private const FINGERPRINT = 'contract-fixture';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('stateful-chunking.rate_limits.enabled', false);
        foreach (['initiate', 'upload', 'status', 'complete', 'cancel'] as $op) {
            RateLimiter::clear('stateful-chunking-'.$op);
        }
    }

    private function hash(): string
    {
        return hash('sha256', self::CONTENT);
    }

    private function initiate(): TestResponse
    {
        return $this->postJson('/api/chunks/initiate', [
            'file_name' => self::FILE_NAME,
            'file_size' => strlen(self::CONTENT),
            'total_chunks' => 1,
            'total_hash' => $this->hash(),
            'fingerprint' => self::FINGERPRINT,
        ]);
    }

    private function initiateAndUpload(): string
    {
        $init = $this->initiate();
        $init->assertStatus(201);
        $sessionId = (string) $init->json('data.session_id');

        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', self::CONTENT);
        $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => $this->hash(),
        ], [], ['file' => $file], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(200);

        return $sessionId;
    }

    /**
     * @param  array<string, mixed>|mixed  $payload
     */
    private function snapshot(string $name, $payload): void
    {
        $this->assertIsArray($payload);
        /** @var array<string, mixed> $payload */
        Snapshot::assertMatches($name, $payload);
    }

    public function test_initiate_contract(): void
    {
        $response = $this->initiate();
        $response->assertStatus(201);
        $this->snapshot('initiate', $response->json());
    }

    public function test_upload_contract(): void
    {
        $init = $this->initiate();
        $init->assertStatus(201);
        $sessionId = (string) $init->json('data.session_id');

        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', self::CONTENT);
        $response = $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => $this->hash(),
        ], [], ['file' => $file], ['HTTP_ACCEPT' => 'application/json']);
        $response->assertStatus(200);

        $this->snapshot('upload', $response->json());
    }

    public function test_status_contract(): void
    {
        $sessionId = $this->initiateAndUpload();

        $response = $this->getJson("/api/chunks/status/{$sessionId}");
        $response->assertStatus(200);

        $this->snapshot('status', $response->json());
    }

    public function test_complete_contract(): void
    {
        $sessionId = $this->initiateAndUpload();

        $response = $this->postJson('/api/chunks/complete', ['session_id' => $sessionId]);
        $response->assertStatus(200);

        $this->snapshot('complete', $response->json());
    }

    public function test_complete_contract_with_server_paths_exposed(): void
    {
        Config::set('stateful-chunking.expose_server_paths', true);

        $sessionId = $this->initiateAndUpload();

        $response = $this->postJson('/api/chunks/complete', ['session_id' => $sessionId]);
        $response->assertStatus(200);

        $this->snapshot('complete_paths_exposed', $response->json());
    }

    public function test_cancel_contract(): void
    {
        $init = $this->initiate();
        $init->assertStatus(201);
        $sessionId = (string) $init->json('data.session_id');

        $response = $this->deleteJson("/api/chunks/cancel/{$sessionId}");
        $response->assertStatus(200);

        $this->snapshot('cancel', $response->json());
    }
}
