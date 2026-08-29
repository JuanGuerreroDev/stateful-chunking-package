<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Core\ValueObjects;

use InvalidArgumentException;

final readonly class ChunkHash
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);
        if (!preg_match('/^[a-f0-9]{64}$/i', $trimmed)) {
            throw new InvalidArgumentException('ChunkHash must be a valid 64-character SHA-256 hex string.');
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
