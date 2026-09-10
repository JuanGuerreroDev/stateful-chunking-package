<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions;

/** The caller attempted to act on a chunk session owned by someone else (IDOR prevented). */
final class UnauthorizedSessionAccessException extends ChunkingException
{
    protected int $statusCode = 403;

    protected string $publicMessage = 'Unauthorized action on chunk session.';

    protected string $logLevel = 'warning';
}
