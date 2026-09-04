<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Domain\Enums;

enum SessionStatus: string
{
    case PENDING = 'pending';
    case UPLOADING = 'uploading';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
