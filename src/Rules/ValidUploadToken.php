<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use StatefulChunking\LaravelPackage\Core\Services\StatefulChunkingService;
use Closure;

final class ValidUploadToken implements ValidationRule
{
    /**
     * Indicates whether the rule should be run for empty attributes.
     */
    public bool $implicit = true;

    public function __construct(
        private readonly StatefulChunkingService $tokenService = new StatefulChunkingService()
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            $fail("The {$attribute} must be a valid string token.");
            return;
        }

        $stagedFile = $this->tokenService->resolveToken($value);

        if (!$stagedFile->isValid()) {
            if ($stagedFile->isExpired()) {
                $fail("The {$attribute} has expired.");
                return;
            }

            $fail("The {$attribute} is invalid or has been tampered with.");
        }
    }
}
