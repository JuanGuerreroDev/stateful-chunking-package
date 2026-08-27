<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Core\ValueObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class SessionId
{
    public readonly string $value;

    public function __construct(?string $value = null)
    {
        $id = $value ?? Str::uuid()->toString();

        if (trim($id) === '') {
            throw new InvalidArgumentException('SessionId cannot be empty.');
        }

        $this->value = $id;
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
