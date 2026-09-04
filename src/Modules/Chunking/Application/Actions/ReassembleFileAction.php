<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Application\Actions;

use StatefulChunking\LaravelPackage\Core\Contracts\FileStorageInterface;
use StatefulChunking\LaravelPackage\Core\Contracts\StateRepositoryInterface;
use StatefulChunking\LaravelPackage\Core\Services\StatefulChunkingService;
use RuntimeException;

final class ReassembleFileAction
{
    public function __construct(
        private readonly StateRepositoryInterface $repository,
        private readonly FileStorageInterface $storage,
        private readonly StatefulChunkingService $tokenService = new StatefulChunkingService()
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

        $uploadToken = $this->tokenService->generateToken(
            sessionId: $sessionId,
            tempPath: $assembledPath,
            fileName: $session->fileName,
            fileSize: $session->fileSize,
            hash: $session->totalHash->value
        );

        $this->repository->deleteSession($sessionId);

        $result = [
            'session_id' => $sessionId,
            'upload_token' => $uploadToken,
            'file_name' => $session->fileName,
            'file_size' => $session->fileSize,
            'path' => $assembledPath,
            'relative_path' => $assembledPath,
            'computed_hash' => $session->totalHash->value,
            'verified' => true,
        ];

        \StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Events\FileReassembled::dispatch(
            sessionId: $sessionId,
            uploadToken: $uploadToken,
            filePath: $assembledPath,
            fileName: $session->fileName,
            fileSize: $session->fileSize,
            hash: $session->totalHash->value,
            reassemblyData: $result
        );

        return $result;
    }
}
