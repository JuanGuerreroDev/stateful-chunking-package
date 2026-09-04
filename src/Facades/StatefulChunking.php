<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Facades;

use Illuminate\Support\Facades\Facade;
use Juanoecr\StatefulChunking\Core\Services\StatefulChunkingService;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\DTOs\StagedFileDTO;

/**
 * @method static string generateToken(string $sessionId, string $tempPath, string $fileName, int $fileSize, string $hash, ?string $disk = null, ?int $ttl = null)
 * @method static StagedFileDTO resolveToken(string $uploadToken)
 *
 * @see \Juanoecr\StatefulChunking\Core\Services\StatefulChunkingService
 */
final class StatefulChunking extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StatefulChunkingService::class;
    }
}
