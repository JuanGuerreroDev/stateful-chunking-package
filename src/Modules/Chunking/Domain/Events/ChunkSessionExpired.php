<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised the moment a session is found to have outlived its TTL, immediately before
 * its state is purged.
 *
 * This exists because expiry used to be silent: the state vanished and the chunks it
 * described stayed on disk with nothing left pointing at them. The event is what lets
 * the staging area be cleaned at the moment of expiry rather than waiting for the next
 * scheduled sweep.
 *
 * It carries totalChunks because by the time a listener runs, the session it describes
 * is already gone and cannot be asked.
 */
final class ChunkSessionExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $sessionId,
        public readonly int $totalChunks
    ) {}
}
