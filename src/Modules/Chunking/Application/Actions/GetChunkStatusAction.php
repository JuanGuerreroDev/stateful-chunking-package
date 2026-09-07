<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions;

use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions\SessionNotFoundException;

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
