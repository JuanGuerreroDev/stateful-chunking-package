<?php

namespace StatefulChunking\LaravelPackage\Tests\Unit;

use InvalidArgumentException;
use StatefulChunking\LaravelPackage\Core\ValueObjects\ChunkHash;
use StatefulChunking\LaravelPackage\Core\ValueObjects\SessionId;
use StatefulChunking\LaravelPackage\Tests\TestCase;

class ValueObjectsAndSanitizationTest extends TestCase
{
    public function test_chunk_hash_requires_exactly_64_hex_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ChunkHash('short_hash_1234');
    }

    public function test_chunk_hash_accepts_valid_sha256(): void
    {
        $validSha256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
        $hash = new ChunkHash($validSha256);
        $this->assertEquals($validSha256, $hash->value);
    }

    public function test_session_id_requires_valid_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SessionId::fromString('invalid-session-id-123');
    }

    public function test_session_id_accepts_valid_uuid(): void
    {
        $validUuid = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';
        $session = SessionId::fromString($validUuid);
        $this->assertEquals(strtolower($validUuid), $session->value);
    }

    public function test_initiate_request_rejects_forbidden_executable_extensions(): void
    {
        $validSha256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $response = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'malicious.php',
            'file_size' => 1024,
            'total_chunks' => 1,
            'total_hash' => $validSha256,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file_name']);
    }

    public function test_initiate_request_rejects_excessive_total_chunks(): void
    {
        $validSha256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $response = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'safe_document.pdf',
            'file_size' => 1024,
            'total_chunks' => 999999,
            'total_hash' => $validSha256,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['total_chunks']);
    }

    public function test_upload_request_rejects_invalid_uuid_session_id(): void
    {
        $validSha256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $response = $this->postJson('/api/chunks/upload', [
            'session_id' => 'not-a-uuid-123',
            'chunk_index' => 0,
            'chunk_hash' => $validSha256,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['session_id']);
    }
}
