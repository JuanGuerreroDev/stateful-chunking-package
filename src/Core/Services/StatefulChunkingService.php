<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Core\Services;

use Illuminate\Support\Facades\Crypt;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\DTOs\StagedFileDTO;
use Throwable;

final class StatefulChunkingService
{
    private function getDefaultDisk(): string
    {
        $disk = config('stateful-chunking.storage_disk', 'local');
        return is_string($disk) ? $disk : 'local';
    }

    private function getDefaultTtl(): int
    {
        $ttl = config('stateful-chunking.session_ttl', 7200);
        return is_numeric($ttl) ? (int) $ttl : 7200;
    }

    public function generateToken(
        string $sessionId,
        string $tempPath,
        string $fileName,
        int $fileSize,
        string $hash,
        ?string $disk = null,
        ?int $ttl = null
    ): string {
        $selectedDisk = $disk ?? $this->getDefaultDisk();
        $selectedTtl = $ttl ?? $this->getDefaultTtl();
        $expiresAt = time() + $selectedTtl;

        $payload = [
            'session_id' => $sessionId,
            'temp_path' => $tempPath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'hash' => $hash,
            'disk' => $selectedDisk,
            'expires_at' => $expiresAt,
            'is_valid' => true,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return Crypt::encryptString($json);
    }

    public function resolveToken(string $uploadToken): StagedFileDTO
    {
        if (trim($uploadToken) === '') {
            return new StagedFileDTO(
                sessionId: '',
                tempPath: '',
                fileName: '',
                fileSize: 0,
                hash: '',
                disk: 'local',
                expiresAt: 0,
                isValid: false
            );
        }

        try {
            $decryptedJson = Crypt::decryptString($uploadToken);
            $decoded = json_decode($decryptedJson, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                return new StagedFileDTO(
                    sessionId: '',
                    tempPath: '',
                    fileName: '',
                    fileSize: 0,
                    hash: '',
                    disk: 'local',
                    expiresAt: 0,
                    isValid: false
                );
            }

            /** @var array<string, mixed> $decoded */
            return StagedFileDTO::fromArray($decoded);
        } catch (Throwable) {
            return new StagedFileDTO(
                sessionId: '',
                tempPath: '',
                fileName: '',
                fileSize: 0,
                hash: '',
                disk: 'local',
                expiresAt: 0,
                isValid: false
            );
        }
    }
}
