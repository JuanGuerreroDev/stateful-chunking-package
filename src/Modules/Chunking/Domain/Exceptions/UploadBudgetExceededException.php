<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions;

/** The cumulative bytes uploaded for a session exceeded the budget its declared file_size allows. */
final class UploadBudgetExceededException extends ChunkingException
{
    protected int $statusCode = 413;

    protected string $publicMessage = 'Upload exceeds the declared file size budget.';

    protected string $logLevel = 'warning';
}
