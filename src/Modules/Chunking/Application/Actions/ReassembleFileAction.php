<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Application\Actions;

use StatefulChunking\LaravelPackage\Core\Contracts\FileStorageInterface;
use StatefulChunking\LaravelPackage\Core\Contracts\StateRepositoryInterface;
use RuntimeException;

final class ReassembleFileAction
{
    public function __construct(
        private readonly StateRepositoryInterface $repository,
        private readonly FileStorageInterface $storage
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $sessionId): array
    {
        $session = $this->repository->getSession($sessionId);
        if (!$session) {
            throw new RuntimeException('Upload session not found or expired.');
        }

        if (!$session->isComplete()) {
            throw new RuntimeException('Cannot reassemble file: Not all chunks are completed.');
        }

        $assembledPath = $this->storage->reassembleFile(
            sessionId: $sessionId,
            fileName: $session->fileName,
            totalChunks: $session->totalChunks,
            expectedTotalHash: $session->totalHash->value
        );

        $this->repository->deleteSession($sessionId);

        return [
            'session_id' => $sessionId,
            'file_name' => $session->fileName,
            'file_size' => $session->fileSize,
            'path' => $assembledPath,
            'relative_path' => $assembledPath,
            'computed_hash' => $session->totalHash->value,
            'verified' => true,
        ];
    }
}
