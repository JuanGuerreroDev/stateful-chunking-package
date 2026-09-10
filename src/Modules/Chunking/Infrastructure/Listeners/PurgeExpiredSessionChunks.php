<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Listeners;

use Juanoecr\StatefulChunkingUpload\Core\Contracts\FileStorageInterface;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Events\ChunkSessionExpired;

/**
 * Deletes the staging directory of a session that has just expired.
 *
 * Why a listener and not a call from the repository: the state repository owns cache
 * entries and the storage adapter owns bytes on disk. Having one reach into the other
 * would couple the two ports that the hexagon deliberately keeps apart, and would make
 * every custom state repository responsible for disk cleanup it knows nothing about.
 * The domain announces that a session expired; whoever cares reacts.
 *
 * Runs synchronously. Queueing it would require the consumer to have queue workers
 * running, and a package that silently depends on that is a package that silently stops
 * collecting garbage. The work is one directory delete.
 *
 * Idempotent by construction: deleting an absent directory is a no-op, so two requests
 * that detect the same expiry concurrently cannot conflict.
 */
final class PurgeExpiredSessionChunks
{
    public function __construct(
        private readonly FileStorageInterface $storage
    ) {}

    public function handle(ChunkSessionExpired $event): void
    {
        $this->storage->deleteTemporaryChunks($event->sessionId, $event->totalChunks);
    }
}
