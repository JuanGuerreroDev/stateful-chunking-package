<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request shape for POST /complete.
 *
 * The endpoint used to validate inline with `required|string`, which accepted any
 * string as a session identifier. Every other endpoint that takes a session id
 * constrains it to a UUID, and that inconsistency is what let an un-canonical
 * identifier reach the ownership check and the paths derived from it. Validating
 * the shape here means the controller can canonicalise the value knowing it is
 * already a UUID.
 */
final class CompleteChunkRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'],
        ];
    }
}
