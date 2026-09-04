<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Core\ValueObjects;

use InvalidArgumentException;

final class ChunkSize
{
    public const CHUNK_MULTIPLE = 262144; // 256 KB exact multiple requirement

    public readonly int $value;

    public function __construct(int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('ChunkSize must be a positive integer.');
        }

        if ($value % self::CHUNK_MULTIPLE !== 0) {
            throw new InvalidArgumentException(
                sprintf('ChunkSize (%d bytes) must be a multiple of %d bytes (256 KB).', $value, self::CHUNK_MULTIPLE)
            );
        }

        $this->value = $value;
    }

    public static function fromInt(int $bytes): self
    {
        return new self($bytes);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toKb(): float
    {
        return $this->value / 1024;
    }

    public function toMb(): float
    {
        return $this->value / (1024 * 1024);
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
