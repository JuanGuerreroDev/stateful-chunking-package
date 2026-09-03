<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;
use StatefulChunking\LaravelPackage\Core\Services\StatefulChunkingService;
use StatefulChunking\LaravelPackage\Modules\Chunking\Application\DTOs\StagedFileDTO;

/**
 * @method static string generateToken(string $sessionId, string $tempPath, string $fileName, int $fileSize, string $hash, ?string $disk = null, ?int $ttl = null)
 * @method static StagedFileDTO resolveToken(string $uploadToken)
 *
 * @see \StatefulChunking\LaravelPackage\Core\Services\StatefulChunkingService
 */
final class StatefulChunking extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StatefulChunkingService::class;
    }
}
