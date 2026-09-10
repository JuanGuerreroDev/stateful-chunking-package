<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\Actions;

use Juanoecr\StatefulChunkingUpload\Core\Contracts\FileStorageInterface;
use Juanoecr\StatefulChunkingUpload\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunkingUpload\Core\Services\StatefulChunkingService;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Events\FileReassembled;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\SessionNotFoundException;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\SessionNotReadyException;

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

        // Derive the staged path, the token and the purge from the resolved session's
        // own identifier rather than from the string we were called with: the value that
        // ends up in a filesystem path should come from the entity, not from the request.
        $resolvedId = $session->sessionId->value;

        $assembledPath = $this->storage->reassembleFile(
            sessionId: $resolvedId,
            fileName: $session->fileName,
            totalChunks: $session->totalChunks,
            expectedTotalHash: $session->totalHash->value
        );

        $uploadToken = $this->tokenService->generateToken(
            sessionId: $resolvedId,
            tempPath: $assembledPath,
            fileName: $session->fileName,
            fileSize: $session->fileSize,
            hash: $session->totalHash->value
        );

        $this->repository->deleteSession($resolvedId);

        $result = [
            'session_id' => $resolvedId,
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
            $resolvedId,
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
