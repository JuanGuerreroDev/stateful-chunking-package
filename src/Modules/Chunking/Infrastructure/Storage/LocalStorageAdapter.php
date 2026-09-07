<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Storage;

use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Core\Contracts\FileStorageInterface;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions\ChunkIntegrityException;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions\StorageFailureException;

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
            if (! hash_equals(strtolower($chunkHash), strtolower($computedHash))) {
                throw new ChunkIntegrityException(
                    sprintf('Chunk %d integrity check failed: SHA-256 hash mismatch.', $chunkIndex),
                    ['session_id' => $sessionId, 'chunk_index' => $chunkIndex]
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
        $finalRelativePath = sprintf('%s/%s/%s', trim($this->getBaseStoragePath(), '/'), $sessionId, $sanitizedFileName);

        $tempFiles = [];
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkRelativePath = $this->chunkPath($sessionId, $i);
            if (! $disk->exists($chunkRelativePath)) {
                throw new StorageFailureException(
                    sprintf('Missing chunk %d for reassembly.', $i),
                    ['session_id' => $sessionId, 'chunk_index' => $i]
                );
            }
            try {
                $tempFiles[] = $disk->path($chunkRelativePath);
            } catch (\Throwable $e) {
                throw new StorageFailureException(
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
            throw new StorageFailureException(
                sprintf(
                    "Storage disk '%s' does not support local filesystem paths. The staging area requires a local disk driver (e.g. 'local'). For remote storage (S3/GCS), use the Staged Upload Pattern to stream the staged file to its permanent destination.",
                    $this->getDiskName()
                ),
                previous: $e
            );
        }
        $dirPath = dirname($fullAbsolutePath);
        if (! is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        $destStream = fopen($fullAbsolutePath, 'wb');
        if (! $destStream) {
            throw new StorageFailureException(
                'Failed to open destination stream for file reassembly.',
                ['session_id' => $sessionId]
            );
        }

        try {
            foreach ($tempFiles as $chunkFile) {
                $srcStream = fopen($chunkFile, 'rb');
                if (! $srcStream) {
                    throw new StorageFailureException(
                        sprintf('Failed to open chunk stream for file: %s', $chunkFile),
                        ['session_id' => $sessionId]
                    );
                }
                try {
                    stream_copy_to_stream($srcStream, $destStream);
                } finally {
                    fclose($srcStream);
                }
            }
        } catch (\Throwable $e) {
            @unlink($fullAbsolutePath);
            throw $e;
        } finally {
            fclose($destStream);
        }

        // Validate assembled file SHA-256 hash if expected hash is provided
        if (strlen($expectedTotalHash) === 64) {
            $assembledHash = hash_file('sha256', $fullAbsolutePath);
            if (! is_string($assembledHash) || ! hash_equals(strtolower($expectedTotalHash), strtolower($assembledHash))) {
                @unlink($fullAbsolutePath);
                throw new ChunkIntegrityException(
                    'Assembled file SHA-256 hash mismatch.',
                    ['session_id' => $sessionId]
                );
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

    public function deleteChunk(string $sessionId, int $chunkIndex): void
    {
        $disk = Storage::disk($this->getDiskName());
        $path = $this->chunkPath($sessionId, $chunkIndex);
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
