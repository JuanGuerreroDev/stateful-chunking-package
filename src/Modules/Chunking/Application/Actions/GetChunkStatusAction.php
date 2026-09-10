<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\Actions;

use Juanoecr\StatefulChunkingUpload\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\SessionNotFoundException;

final class GetChunkStatusAction
{
    public function __construct(
        private readonly StateRepositoryInterface $repository
    ) {}

    public function handle(string $sessionId): ChunkSession
    {
        $session = $this->repository->getSession($sessionId);
        if (! $session) {
            throw new SessionNotFoundException(
                'Status requested for unknown session.',
                ['session_id' => $sessionId]
            );
        }

        return $session;
    }
}
