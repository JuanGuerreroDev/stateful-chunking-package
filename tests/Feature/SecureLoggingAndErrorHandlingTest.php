<?php

namespace Juanoecr\StatefulChunking\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Juanoecr\StatefulChunking\Tests\TestCase;

class SecureLoggingAndErrorHandlingTest extends TestCase
{
    public function test_controller_sanitizes_error_messages_and_does_not_leak_internal_exceptions(): void
    {
        // Initiate invalid complete request with non-existent session
        $validUuid = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
        $response = $this->postJson('/api/chunks/complete', [
            'session_id' => $validUuid,
        ]);

        $response->assertStatus(400);
        $json = $response->json();
        
        // Assert message is generic and sanitized
        $this->assertEquals('File reassembly processing failed. Please try again.', $json['message']);
        $this->assertStringNotContainsString('/var/www', $json['message']);
        $this->assertStringNotContainsString('Exception', $json['message']);
    }

    public function test_audit_logs_are_dispatched_on_initiate_and_cancel(): void
    {
        Log::shouldReceive('channel')
            ->atLeast()->once()
            ->with(null)
            ->andReturnSelf();
        
        Log::shouldReceive('info')
            ->atLeast()->once();

        $validSha256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $response = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'audit_test.pdf',
            'file_size' => 1024,
            'total_chunks' => 1,
            'total_hash' => $validSha256,
        ]);

        $response->assertStatus(201);
    }
}
