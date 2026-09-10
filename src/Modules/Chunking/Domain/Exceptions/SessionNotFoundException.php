<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions;

/** The referenced upload session does not exist (or has already expired and been purged). */
final class SessionNotFoundException extends ChunkingException
{
    protected int $statusCode = 404;

    protected string $publicMessage = 'Upload session not found.';

    protected string $logLevel = 'info';
}
