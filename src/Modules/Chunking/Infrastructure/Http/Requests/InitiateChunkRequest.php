<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class InitiateChunkRequest extends FormRequest
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
        $rawMaxFileSize = config('stateful-chunking.max_file_size_bytes', 10737418240);
        $maxFileSize = is_numeric($rawMaxFileSize) ? (int) $rawMaxFileSize : 10737418240;

        $rawMaxChunks = config('stateful-chunking.max_total_chunks', 10000);
        $maxChunks = is_numeric($rawMaxChunks) ? (int) $rawMaxChunks : 10000;

        $rawForbiddenExts = config('stateful-chunking.forbidden_extensions', ['php', 'phar', 'phtml', 'sh', 'exe', 'bat', 'cgi', 'pl']);
        $forbiddenExts = is_array($rawForbiddenExts) ? $rawForbiddenExts : ['php', 'phar', 'phtml', 'sh', 'exe', 'bat', 'cgi', 'pl'];

        return [
            'file_name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9._-]+$/',
                function (string $attribute, mixed $value, \Closure $fail) use ($forbiddenExts): void {
                    $ext = strtolower(pathinfo(is_string($value) ? $value : '', PATHINFO_EXTENSION));
                    if (in_array($ext, $forbiddenExts, true)) {
                        $fail("The file extension .{$ext} is forbidden for uploads.");
                    }
                },
            ],
            'file_size' => ['required', 'integer', 'min:1', 'max:' . $maxFileSize],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:' . $maxChunks],
            'total_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'fingerprint' => ['nullable', 'string', 'max:255'],
        ];
    }
}
