<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions;

use Juanoecr\StatefulChunking\Core\Contracts\FileStorageInterface;
use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Core\Services\StatefulChunkingService;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events\FileReassembled;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions\SessionNotFoundException;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions\SessionNotReadyException;

final class ReassembleFileAction
{
    public function __construct(
        private readonly StateRepositoryInterface $repository,
        private readonly FileStorageInterface $storage,
        private readonly StatefulChunkingService $tokenService = new StatefulChunkingService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $sessionId): array
    {
        // Serialise reassembly per session: two concurrent /complete calls must not
        // both pass the isComplete() check and reassemble (and mint tokens) twice.
        // The second caller waits, then finds the session already consumed -> 404.
        /** @var array<string, mixed> $result */
        $result = $this->repository->withSessionLock($sessionId, fn (): array => $this->reassemble($sessionId));

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function reassemble(string $sessionId): array
    {
        $session = $this->repository->getSession($sessionId);
        if (! $session) {
            throw new SessionNotFoundException(
                'Reassembly requested for unknown or expired session.',
                ['session_id' => $sessionId]
            );
        }

        if (! $session->isComplete()) {
            throw new SessionNotReadyException(
                'Reassembly requested before all chunks were uploaded.',
                ['session_id' => $sessionId, 'pending_chunks' => $session->getPendingChunkIndices()]
            );
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

        // Positional args (not named): Laravel 10/11's Dispatchable::dispatch()
        // forwards via func_get_args(), which drops named arguments and throws
        // "Unknown named parameter". Positional works across Laravel 10-13.
        // Order matches FileReassembled::__construct().
        FileReassembled::dispatch(
            $sessionId,
            $uploadToken,
            $assembledPath,
            $session->fileName,
            $session->fileSize,
            $session->totalHash->value,
            $result
        );

        return $result;
    }
}
