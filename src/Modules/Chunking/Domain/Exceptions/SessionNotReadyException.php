<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions;

/** Reassembly was requested before every chunk of the session was uploaded. */
final class SessionNotReadyException extends ChunkingException
{
    protected int $statusCode = 409;

    protected string $publicMessage = 'Upload session is not ready for reassembly.';

    protected string $logLevel = 'info';
}
