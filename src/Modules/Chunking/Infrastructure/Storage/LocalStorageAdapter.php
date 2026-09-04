<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Storage;

use Illuminate\Support\Facades\Storage;
use StatefulChunking\LaravelPackage\Core\Contracts\FileStorageInterface;
use StatefulChunking\LaravelPackage\Core\ValueObjects\ChunkHash;
use RuntimeException;

final class LocalStorageAdapter implements FileStorageInterface
{
    private function getDiskName(): string
    {
        $disk = config('stateful-chunking.storage_disk', 'local');
        return is_string($disk) ? $disk : 'local';
    }

    private function getBaseStoragePath(): string
    {
        $path = config('stateful-chunking.storage_path', 'uploads');
        return is_string($path) ? $path : 'uploads';
    }

    private function chunkPath(string $sessionId, int $chunkIndex): string
    {
        return sprintf('chunks_temp/%s/chunk_%d.tmp', $sessionId, $chunkIndex);
    }

    public function storeChunk(string $sessionId, int $chunkIndex, string $content, string $chunkHash): string
    {
        $disk = Storage::disk($this->getDiskName());

        // Validate chunk checksum
        $computedHash = hash('sha256', $content);

        if (strlen($chunkHash) >= 8 && strlen($chunkHash) === 64) {
            if (strtolower($computedHash) !== strtolower($chunkHash)) {
                throw new RuntimeException(
                    sprintf('Chunk %d integrity check failed: SHA-256 hash mismatch.', $chunkIndex)
                );
            }
        }

        $path = $this->chunkPath($sessionId, $chunkIndex);
        $disk->put($path, $content);

        return $path;
    }

    public function reassembleFile(
        string $sessionId,
        string $fileName,
        int $totalChunks,
        string $expectedTotalHash
    ): string {
        $disk = Storage::disk($this->getDiskName());
        $sanitizedFileName = basename($fileName);
        $finalRelativePath = sprintf('%s/%s', trim($this->getBaseStoragePath(), '/'), $sanitizedFileName);

        $tempFiles = [];
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkRelativePath = $this->chunkPath($sessionId, $i);
            if (!$disk->exists($chunkRelativePath)) {
                throw new RuntimeException(sprintf('Missing chunk %d for reassembly.', $i));
            }
            try {
                $tempFiles[] = $disk->path($chunkRelativePath);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    sprintf(
                        "Storage disk '%s' does not support local filesystem paths. The staging area requires a local disk driver (e.g. 'local'). For remote storage (S3/GCS), use the Staged Upload Pattern to stream the staged file to its permanent destination.",
                        $this->getDiskName()
                    ),
                    previous: $e
                );
            }
        }

        try {
            $fullAbsolutePath = $disk->path($finalRelativePath);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                sprintf(
                    "Storage disk '%s' does not support local filesystem paths. The staging area requires a local disk driver (e.g. 'local'). For remote storage (S3/GCS), use the Staged Upload Pattern to stream the staged file to its permanent destination.",
                    $this->getDiskName()
                ),
                previous: $e
            );
        }
        $dirPath = dirname($fullAbsolutePath);
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        $destStream = fopen($fullAbsolutePath, 'wb');
        if (!$destStream) {
            throw new RuntimeException('Failed to open destination stream for file reassembly.');
        }

        try {
            foreach ($tempFiles as $chunkFile) {
                $srcStream = fopen($chunkFile, 'rb');
                if (!$srcStream) {
                    throw new RuntimeException(sprintf('Failed to open chunk stream for file: %s', $chunkFile));
                }
                stream_copy_to_stream($srcStream, $destStream);
                fclose($srcStream);
            }
        } finally {
            fclose($destStream);
        }

        // Validate assembled file SHA-256 hash if expected hash is provided
        if (strlen($expectedTotalHash) === 64) {
            $assembledHash = hash_file('sha256', $fullAbsolutePath);
            if (!is_string($assembledHash) || strtolower($assembledHash) !== strtolower($expectedTotalHash)) {
                @unlink($fullAbsolutePath);
                throw new RuntimeException('Assembled file SHA-256 hash mismatch');
            }
        }

        $this->deleteTemporaryChunks($sessionId, $totalChunks);

        return $finalRelativePath;
    }

    public function deleteTemporaryChunks(string $sessionId, int $totalChunks): void
    {
        $disk = Storage::disk($this->getDiskName());
        $tempDir = sprintf('chunks_temp/%s', $sessionId);
        $disk->deleteDirectory($tempDir);
    }
}
