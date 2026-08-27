<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Application\Actions;

use StatefulChunking\LaravelPackage\Core\Contracts\StateRepositoryInterface;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Entities\ChunkSession;
use RuntimeException;

final class GetChunkStatusAction
{
    public function __construct(
        private readonly StateRepositoryInterface $repository
    ) {}

    public function handle(string $sessionId): ChunkSession
    {
        $session = $this->repository->getSession($sessionId);
        if (!$session) {
            throw new RuntimeException('Upload session not found.');
        }

        return $session;
    }
}
