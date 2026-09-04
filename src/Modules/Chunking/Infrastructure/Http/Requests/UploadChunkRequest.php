<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rawChunkSize = config('stateful-chunking.chunk_size_bytes', 2097152);
        $chunkSizeBytes = is_numeric($rawChunkSize) && (int) $rawChunkSize > 0 ? (int) $rawChunkSize : 2097152;
        // Allow a 10% buffer for protocol and multipart transport overhead
        $maxAllowedBytes = (int) ($chunkSizeBytes * 1.1);
        $maxAllowedKb = (int) ceil($maxAllowedBytes / 1024);

        return [
            'session_id' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'chunk_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'file' => ['nullable', 'file', 'max:' . $maxAllowedKb],
        ];
    }
}
