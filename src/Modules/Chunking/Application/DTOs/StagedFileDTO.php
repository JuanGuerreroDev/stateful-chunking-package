<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Application\DTOs;

use Illuminate\Support\Facades\Storage;

final readonly class StagedFileDTO
{
    public function __construct(
        public string $sessionId,
        public string $tempPath,
        public string $fileName,
        public int $fileSize,
        public string $hash,
        public string $disk,
        public int $expiresAt,
        public bool $isValid = true
    ) {}

    public function isValid(): bool
    {
        return $this->isValid && !$this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expiresAt > 0 && time() > $this->expiresAt;
    }

    public function mimeType(): ?string
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($this->disk);
        $mime = $disk->mimeType($this->tempPath);
        return is_string($mime) ? $mime : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'temp_path' => $this->tempPath,
            'file_name' => $this->fileName,
            'file_size' => $this->fileSize,
            'hash' => $this->hash,
            'disk' => $this->disk,
            'expires_at' => $this->expiresAt,
            'is_valid' => $this->isValid(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $sessionId = isset($data['session_id']) && is_string($data['session_id']) ? $data['session_id'] : '';
        $tempPath = isset($data['temp_path']) && is_string($data['temp_path']) ? $data['temp_path'] : '';
        $fileName = isset($data['file_name']) && is_string($data['file_name']) ? $data['file_name'] : '';
        $fileSize = isset($data['file_size']) && is_numeric($data['file_size']) ? (int) $data['file_size'] : 0;
        $hash = isset($data['hash']) && is_string($data['hash']) ? $data['hash'] : '';
        $disk = isset($data['disk']) && is_string($data['disk']) ? $data['disk'] : 'local';
        $expiresAt = isset($data['expires_at']) && is_numeric($data['expires_at']) ? (int) $data['expires_at'] : 0;
        $isValid = isset($data['is_valid']) ? (bool) $data['is_valid'] : true;

        return new self(
            sessionId: $sessionId,
            tempPath: $tempPath,
            fileName: $fileName,
            fileSize: $fileSize,
            hash: $hash,
            disk: $disk,
            expiresAt: $expiresAt,
            isValid: $isValid
        );
    }
}
