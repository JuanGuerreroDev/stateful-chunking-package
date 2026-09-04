<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ChunkSessionCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $sessionId
    ) {}
}
