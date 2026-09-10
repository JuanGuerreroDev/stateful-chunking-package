<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Facades;

use Illuminate\Support\Facades\Facade;
use Juanoecr\StatefulChunkingUpload\Core\Services\StatefulChunkingService;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\DTOs\StagedFileDTO;

/**
 * @method static string generateToken(string $sessionId, string $tempPath, string $fileName, int $fileSize, string $hash, ?string $disk = null, ?int $ttl = null)
 * @method static StagedFileDTO resolveToken(string $uploadToken)
 *
 * @see StatefulChunkingService
 */
final class StatefulChunking extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StatefulChunkingService::class;
    }
}
