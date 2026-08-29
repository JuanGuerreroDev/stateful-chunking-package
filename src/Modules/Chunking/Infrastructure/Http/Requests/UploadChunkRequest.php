<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'chunk_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'file' => ['nullable'],
        ];
    }
}
