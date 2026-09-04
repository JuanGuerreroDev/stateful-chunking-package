<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Events\ChunkSessionCancelled;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Events\ChunkSessionInitiated;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Events\ChunkUploaded;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Events\FileReassembled;
use StatefulChunking\LaravelPackage\Tests\TestCase;

final class DomainEventsDispatchTest extends TestCase
{
    public function test_domain_events_lifecycle_dispatch(): void
    {
        Event::fake([
            ChunkSessionInitiated::class,
            ChunkUploaded::class,
            FileReassembled::class,
            ChunkSessionCancelled::class,
        ]);

        Storage::fake('local');

        $chunk1 = 'FirstChunkContent_';
        $chunk2 = 'SecondChunkContent';
        $fullContent = $chunk1 . $chunk2;
        $totalHash = hash('sha256', $fullContent);
        $chunk1Hash = hash('sha256', $chunk1);
        $chunk2Hash = hash('sha256', $chunk2);

        // 1. Initiate session
        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'events_test_file.txt',
            'file_size' => strlen($fullContent),
            'total_chunks' => 2,
            'total_hash' => $totalHash,
        ]);

        $initiateResponse->assertStatus(201);
        $sessionId = (string) $initiateResponse->json('data.session_id');

        Event::assertDispatched(ChunkSessionInitiated::class, function (ChunkSessionInitiated $event) use ($sessionId) {
            return $event->session->sessionId->value === $sessionId
                && $event->session->fileName === 'events_test_file.txt';
        });

        // 2. Upload Chunk 0
        $fileChunk1 = UploadedFile::fake()->createWithContent('chunk_0.tmp', $chunk1);
        $upload1Response = $this->postJson('/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => $chunk1Hash,
            'file' => $fileChunk1,
        ]);
        $upload1Response->assertStatus(200);

        Event::assertDispatched(ChunkUploaded::class, function (ChunkUploaded $event) use ($sessionId, $chunk1Hash) {
            return $event->session->sessionId->value === $sessionId
                && $event->chunkIndex === 0
                && $event->chunkHash === $chunk1Hash;
        });

        // 3. Upload Chunk 1
        $fileChunk2 = UploadedFile::fake()->createWithContent('chunk_1.tmp', $chunk2);
        $upload2Response = $this->postJson('/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 1,
            'chunk_hash' => $chunk2Hash,
            'file' => $fileChunk2,
        ]);
        $upload2Response->assertStatus(200);

        Event::assertDispatched(ChunkUploaded::class, function (ChunkUploaded $event) use ($sessionId, $chunk2Hash) {
            return $event->session->sessionId->value === $sessionId
                && $event->chunkIndex === 1
                && $event->chunkHash === $chunk2Hash;
        });

        // 4. Complete reassembly
        $completeResponse = $this->postJson('/api/chunks/complete', [
            'session_id' => $sessionId,
        ]);
        $completeResponse->assertStatus(200);

        Event::assertDispatched(FileReassembled::class, function (FileReassembled $event) use ($sessionId, $totalHash) {
            return $event->sessionId === $sessionId
                && $event->fileName === 'events_test_file.txt'
                && $event->hash === $totalHash
                && !empty($event->uploadToken);
        });
    }

    public function test_cancel_session_dispatches_cancelled_event(): void
    {
        Event::fake([ChunkSessionCancelled::class]);
        Storage::fake('local');

        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'to_cancel.txt',
            'file_size' => 10,
            'total_chunks' => 1,
            'total_hash' => hash('sha256', 'to_cancel!'),
        ]);

        $sessionId = (string) $initiateResponse->json('data.session_id');

        $cancelResponse = $this->deleteJson("/api/chunks/cancel/{$sessionId}");
        $cancelResponse->assertStatus(200);

        Event::assertDispatched(ChunkSessionCancelled::class, function (ChunkSessionCancelled $event) use ($sessionId) {
            return $event->sessionId === $sessionId;
        });
    }
}
