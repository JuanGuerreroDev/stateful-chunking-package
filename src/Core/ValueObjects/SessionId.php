<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Core\ValueObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class SessionId
{
    public string $value;

    public function __construct(?string $value = null)
    {
        $id = $value ?? Str::uuid()->toString();

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            throw new InvalidArgumentException('SessionId must be a valid UUID v4 string.');
        }

        $this->value = strtolower($id);
    }

    public static function generate(): self
    {
        return new self();
    }

    public static function fromString(string $id): self
    {
        return new self($id);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
