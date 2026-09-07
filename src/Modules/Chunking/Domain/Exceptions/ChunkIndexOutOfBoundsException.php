<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions;

/** A chunk index outside the session's declared [0, totalChunks) range was submitted. */
final class ChunkIndexOutOfBoundsException extends ChunkingException
{
    protected int $statusCode = 422;

    protected string $publicMessage = 'Chunk index is out of bounds for this session.';

    protected string $logLevel = 'warning';
}
