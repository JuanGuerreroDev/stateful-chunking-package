<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Entities\ChunkSession;

final class ChunkUploaded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ChunkSession $session,
        public readonly int $chunkIndex,
        public readonly string $chunkHash
    ) {}
}
