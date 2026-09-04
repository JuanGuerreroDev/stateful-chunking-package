<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class FileReassembled
{
    use Dispatchable, SerializesModels;

    /**
     * @param array<string, mixed> $reassemblyData
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $uploadToken,
        public readonly string $filePath,
        public readonly string $fileName,
        public readonly int $fileSize,
        public readonly string $hash,
        public readonly array $reassemblyData = []
    ) {}
}
