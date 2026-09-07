<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions;

/** A chunk or the assembled file failed SHA-256 integrity verification. */
final class ChunkIntegrityException extends ChunkingException
{
    protected int $statusCode = 422;

    protected string $publicMessage = 'Chunk integrity verification failed.';

    protected string $logLevel = 'warning';
}
