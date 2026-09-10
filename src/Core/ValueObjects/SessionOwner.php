<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Core\ValueObjects;

use InvalidArgumentException;

/**
 * Who a chunk session belongs to.
 *
 * This was the last identity in the package that stayed a raw string. `ChunkSession`
 * held it as `?string` while the rule it had to satisfy lived in an infrastructure
 * class, so the aggregate persisted a value whose format it did not own and could not
 * enforce — the opposite of the convention `SessionId` and `ChunkHash` already set.
 *
 * The invariant it owns is **scheme qualification**: an owner is `<scheme>:<value>`.
 * That is not decoration. Without it, the user whose id is `1.2.3.4` and the caller
 * arriving from the address `1.2.3.4` are the same owner, and share a rate-limit
 * bucket. AF-004's fix promised that separation in the CHANGELOG; this is where the
 * promise becomes enforceable rather than conventional.
 *
 * The scheme deliberately is not an enum. `ResolvesCallerIdentity` is an extension
 * point, and a deployment whose caller is a tenant, an API key or a calling service
 * must be able to name its own scheme — `tenant:7:user:42` is valid, because only the
 * first segment is the scheme and the rest is opaque to us.
 *
 * ADR: docs/decisions/0003-normalise-identity-at-the-adapter-boundary.md
 */
final readonly class SessionOwner
{
    /**
     * Total length cap. The value is persisted in the session payload of every cache
     * driver, including ones with modest per-entry limits.
     */
    public const MAX_LENGTH = 255;

    public string $value;

    public function __construct(string $value)
    {
        $candidate = trim($value);

        if ($candidate === '') {
            throw new InvalidArgumentException('SessionOwner cannot be empty.');
        }

        if (strlen($candidate) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('SessionOwner cannot exceed %d characters.', self::MAX_LENGTH)
            );
        }

        // <scheme>:<value> — the scheme is an identifier, the value is anything
        // non-empty, colons included, so nested schemes remain expressible.
        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*:.+$/', $candidate)) {
            throw new InvalidArgumentException(
                'SessionOwner must be scheme-qualified as "<scheme>:<value>", for example "user:42" or "ip:203.0.113.7". '
                .'A bare identifier is rejected because it cannot be told apart from an identifier of another kind.'
            );
        }

        $this->value = $candidate;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /**
     * The same string, or null when there is nothing to build an owner from.
     *
     * Used where absence is meaningful and malformed input is not: reading a session
     * back from a store whose payload has been truncated or hand-edited. An owner that
     * cannot be parsed becomes no owner, and a session with no owner belongs to nobody,
     * so it fails closed rather than becoming public.
     */
    public static function tryFromString(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return new self($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The part before the first colon: `user`, `ip`, `tenant`, …
     */
    public function scheme(): string
    {
        $position = strpos($this->value, ':');

        return $position === false ? $this->value : substr($this->value, 0, $position);
    }

    /**
     * Exact, case-sensitive equality, and never true for a null counterpart.
     *
     * Case sensitivity is deliberate: owner values are opaque to this package, and
     * folding case could merge two distinct identities from a consumer whose scheme
     * carries a case-sensitive token.
     */
    public function equals(?self $other): bool
    {
        return $other !== null && $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
