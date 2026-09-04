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

        $rawForbiddenExts = config('stateful-chunking.forbidden_extensions', [
            'php', 'phar', 'phtml', 'pht', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'inc', 'hphp', 'ctp',
            'sh', 'bash', 'zsh', 'exe', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py', 'rb', 'vbs', 'vbe', 'ps1',
            'asp', 'aspx', 'cer', 'asa', 'asax', 'cfm', 'cfc', 'jsp', 'jspx', 'shtml', 'shtm',
            'htaccess', 'htpasswd', 'user.ini',
        ]);
        $forbiddenExts = is_array($rawForbiddenExts)
            ? array_map('strtolower', array_map('trim', $rawForbiddenExts))
            : ['php', 'phar', 'phtml', 'sh', 'exe', 'bat', 'cgi', 'pl'];

        $rawAllowedExts = config('stateful-chunking.allowed_extensions');
        $allowedExts = is_array($rawAllowedExts) && count($rawAllowedExts) > 0
            ? array_map('strtolower', array_map('trim', $rawAllowedExts))
            : null;

        return [
            'file_name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9._-]+$/',
                function (string $attribute, mixed $value, \Closure $fail) use ($forbiddenExts, $allowedExts): void {
                    $strValue = is_string($value) ? $value : '';

                    // 1. Block dot-files (e.g., .htaccess, .env)
                    if (str_starts_with($strValue, '.')) {
                        $fail('Filenames starting with a dot are forbidden.');
                        return;
                    }

                    // 2. Block trailing dots or spaces (Windows normalization bypass)
                    if (str_ends_with($strValue, '.') || str_ends_with($strValue, ' ')) {
                        $fail('Filenames with trailing dots or spaces are forbidden.');
                        return;
                    }

                    $segments = explode('.', $strValue);

                    // 3. Must contain at least one dot separating name and extension
                    if (count($segments) < 2 || end($segments) === '') {
                        $fail('The filename must contain a valid extension.');
                        return;
                    }

                    $finalExtension = strtolower((string) end($segments));

                    // 4. Enforce whitelist if configured
                    if ($allowedExts !== null) {
                        if (!in_array($finalExtension, $allowedExts, true)) {
                            $fail("The file extension .{$finalExtension} is not allowed.");
                            return;
                        }
                    }

                    // 5. Multi-segment inspection (double extension prevention)
                    // Check every segment after the root stem against forbidden extensions
                    foreach (array_slice($segments, 1) as $segment) {
                        $cleanSegment = strtolower(trim($segment));
                        if (in_array($cleanSegment, $forbiddenExts, true)) {
                            $fail("The file extension or component .{$cleanSegment} is forbidden for uploads.");
                            return;
                        }
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
