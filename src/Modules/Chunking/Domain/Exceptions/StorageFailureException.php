<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions;

/** An unexpected failure occurred in the storage adapter (disk I/O, unsupported driver, etc.). */
final class StorageFailureException extends ChunkingException
{
    protected int $statusCode = 500;

    protected string $publicMessage = 'File storage operation failed. Please try again.';

    protected string $logLevel = 'error';
}
