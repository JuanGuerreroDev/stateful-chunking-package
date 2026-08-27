<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use StatefulChunking\LaravelPackage\Core\ValueObjects\ChunkSize;

final class InitiateChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxFileSize = config('stateful-chunking.max_file_size_bytes', 10737418240);

        return [
            'file_name' => ['required', 'string', 'max:255'],
            'file_size' => ['required', 'integer', 'min:1', 'max:' . $maxFileSize],
            'total_chunks' => ['required', 'integer', 'min:1'],
            'total_hash' => ['required', 'string', 'min:8'],
            'fingerprint' => ['nullable', 'string', 'max:255'],
        ];
    }
}
