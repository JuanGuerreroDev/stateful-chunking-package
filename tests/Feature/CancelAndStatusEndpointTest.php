<?php

namespace Juanoecr\StatefulChunking\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Tests\TestCase;

class CancelAndStatusEndpointTest extends TestCase
{
    public function test_cancel_session_purges_resources(): void
    {
        Storage::fake('local');
        $hash = hash('sha256', 'CANCEL_TEST_CONTENT');

        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'cancel_doc.txt',
            'file_size' => 19,
            'total_chunks' => 1,
            'total_hash' => $hash,
        ]);

        $initiateResponse->assertStatus(201);
        $sessionId = $initiateResponse->json('data.session_id');

        $cancelResponse = $this->deleteJson("/api/chunks/cancel/{$sessionId}");
        $cancelResponse->assertStatus(200)
            ->assertJson(['message' => 'Session cancelled and resources purged']);

        $statusResponse = $this->getJson("/api/chunks/status/{$sessionId}");
        $statusResponse->assertStatus(404)
            ->assertJson(['message' => 'Session status not found.']);
    }

    public function test_status_returns_404_for_non_existent_session(): void
    {
        $nonExistentId = '11111111-2222-4333-8444-555555555555';
        $response = $this->getJson("/api/chunks/status/{$nonExistentId}");
        $response->assertStatus(404);
    }
}
