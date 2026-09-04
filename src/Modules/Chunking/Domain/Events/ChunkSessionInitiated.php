<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;

final class ChunkSessionInitiated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ChunkSession $session
    ) {}
}
