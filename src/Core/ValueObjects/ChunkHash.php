<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Core\ValueObjects;

use InvalidArgumentException;

final class ChunkHash
{
    public readonly string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);
        if (strlen($trimmed) < 8) {
            throw new InvalidArgumentException('ChunkHash must be a valid hash string of at least 8 characters.');
        }

        $this->value = strtolower($trimmed);
    }

    public static function fromString(string $hash): self
    {
        return new self($hash);
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
