<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Tests\TestCase;
use RuntimeException;

/**
 * VULN-12 REGRESSION TEST: Orphan Chunks Cleanup on Exception / Rollback
 *
 * Security Invariants:
 * 1. If physical chunk write succeeds but state update (updateChunkStatus) fails in the repository,
 *    the temporary chunk file MUST NOT remain orphaned on disk. It must be rolled back (deleted).
 * 2. If reassembleFile fails midway, any partially assembled destination file MUST be cleaned up.
 */
class Vuln12OrphanChunksRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * REGRESSION 1: Temporary chunk file is deleted if repository updateChunkStatus fails.
     */
    public function test_chunk_file_is_cleaned_up_if_state_update_fails(): void
    {
        $chunkData = 'ORPHAN_CHUNK_TEST_DATA';
        $chunkHash = hash('sha256', $chunkData);

        // Initiate session first to get valid session id
        $initRes = $this->postJson('/api/chunks/initiate', [
            'file_name'    => 'orphan_test.txt',
            'file_size'    => strlen($chunkData),
            'total_chunks' => 1,
            'total_hash'   => $chunkHash,
            'fingerprint'  => 'orphan_fp_' . uniqid(),
        ]);

        $initRes->assertStatus(201);
        $sessionId = $initRes->json('data.session_id');

        // Now bind a decorator/proxy on StateRepositoryInterface that throws on updateChunkStatus
        $realRepo = app(StateRepositoryInterface::class);
        $repoMock = $this->createMock(StateRepositoryInterface::class);
        $repoMock->method('getSession')
            ->willReturnCallback(fn (string $id): ?ChunkSession => $realRepo->getSession($id));
        $repoMock->method('updateChunkStatus')
            ->willThrowException(new RuntimeException('Simulated Cache/Store connection drop during updateChunkStatus'));
        $this->app->instance(StateRepositoryInterface::class, $repoMock);

        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', $chunkData);
        $response = $this->call(
            'POST',
            '/api/chunks/upload',
            [
                'session_id'  => $sessionId,
                'chunk_index' => 0,
                'chunk_hash'  => $chunkHash,
            ],
            [],
            ['file' => $file],
            ['HTTP_ACCEPT' => 'application/json']
        );

        // Upload endpoint fails with 400 (caught by controller)
        $response->assertStatus(400);

        // Critical Assertion: The physical chunk file MUST NOT exist on disk!
        $disk = Storage::disk('local');
        $chunkPath = sprintf('chunks_temp/%s/chunk_0.tmp', $sessionId);

        $this->assertFalse(
            $disk->exists($chunkPath),
            "Vulnerability confirmed: Orphan chunk file '{$chunkPath}' remained on disk after state update failure."
        );
    }

    /**
     * REGRESSION 2: Assembled destination file is deleted if reassembly fails or hash mismatches.
     */
    public function test_partial_assembled_file_is_cleaned_up_if_reassembly_hash_mismatches(): void
    {
        $chunkData = 'CORRUPTED_FILE_DATA';
        $claimedHash = str_repeat('f', 64); // Mismatched expected total hash

        $initRes = $this->postJson('/api/chunks/initiate', [
            'file_name'    => 'clean_assembled_test.txt',
            'file_size'    => strlen($chunkData),
            'total_chunks' => 1,
            'total_hash'   => $claimedHash,
            'fingerprint'  => 'orphan_reassemble_fp_' . uniqid(),
        ]);

        $initRes->assertStatus(201);
        $sessionId = $initRes->json('data.session_id');

        // Upload chunk 0
        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', $chunkData);
        $this->call('POST', '/api/chunks/upload', [
            'session_id'  => $sessionId,
            'chunk_index' => 0,
            'chunk_hash'  => hash('sha256', $chunkData),
        ], [], ['file' => $file], ['HTTP_ACCEPT' => 'application/json']);

        // Trigger complete - should fail because assembled file hash mismatches claimedHash
        $completeRes = $this->postJson('/api/chunks/complete', [
            'session_id' => $sessionId,
        ]);

        $completeRes->assertStatus(400);

        // Assert no assembled file exists on disk
        $disk = Storage::disk('local');
        $assembledPath = sprintf('uploads/%s/clean_assembled_test.txt', $sessionId);
        $this->assertFalse(
            $disk->exists($assembledPath),
            "Vulnerability confirmed: Orphan assembled file '{$assembledPath}' remained on disk after failure."
        );
    }
}
