<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Unit;

use InvalidArgumentException;
use Juanoecr\StatefulChunking\Core\ValueObjects\SessionOwner;
use Juanoecr\StatefulChunking\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The rule this value object exists to own: an owner identity is scheme-qualified.
 *
 * Without it, the user whose id is `1.2.3.4` and the caller arriving from the address
 * `1.2.3.4` are indistinguishable, so they share a session ownership check and a
 * rate-limit bucket. The CHANGELOG promised that separation when AF-004 was fixed;
 * these tests are where the promise stops being a convention.
 */
class SessionOwnerTest extends TestCase
{
    #[DataProvider('acceptedIdentities')]
    public function test_a_scheme_qualified_identity_is_accepted(string $value, string $expectedScheme): void
    {
        $owner = SessionOwner::fromString($value);

        $this->assertSame($value, $owner->value);
        $this->assertSame($expectedScheme, $owner->scheme());
        $this->assertSame($value, (string) $owner);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function acceptedIdentities(): array
    {
        return [
            'an authenticated user' => ['user:42', 'user'],
            'a source address' => ['ip:203.0.113.7', 'ip'],
            'an IPv6 address' => ['ip:2001:db8::1', 'ip'],
            'a consumer scheme' => ['tenant:7', 'tenant'],
            // The extension point must survive: only the first segment is the scheme,
            // so a nested identity from a custom resolver stays expressible.
            'a nested consumer scheme' => ['tenant:7:user:42', 'tenant'],
            'a hyphenated scheme' => ['api-key:abc123', 'api-key'],
        ];
    }

    #[DataProvider('rejectedIdentities')]
    public function test_an_unqualified_or_empty_identity_is_rejected(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        SessionOwner::fromString($value);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedIdentities(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ["  \t "],
            // The case that matters: a bare id cannot be told apart from an address.
            'a bare identifier' => ['42'],
            'a bare address' => ['203.0.113.7'],
            'a scheme with no value' => ['user:'],
            'a value with no scheme' => [':42'],
            'a scheme starting with a digit' => ['1user:42'],
            'too long' => ['user:'.str_repeat('x', SessionOwner::MAX_LENGTH)],
        ];
    }

    public function test_surrounding_whitespace_is_trimmed_rather_than_stored(): void
    {
        $this->assertSame('user:42', SessionOwner::fromString('  user:42  ')->value);
    }

    public function test_equality_is_exact_and_never_true_against_nothing(): void
    {
        $owner = SessionOwner::fromString('user:42');

        $this->assertTrue($owner->equals(SessionOwner::fromString('user:42')));
        $this->assertFalse($owner->equals(SessionOwner::fromString('user:43')));
        $this->assertFalse($owner->equals(null));

        // Two identities that differ only in scheme are different owners. This is the
        // collision the scheme prefix exists to prevent.
        $this->assertFalse(SessionOwner::fromString('user:1.2.3.4')->equals(SessionOwner::fromString('ip:1.2.3.4')));
    }

    public function test_case_is_preserved_and_significant(): void
    {
        $this->assertFalse(
            SessionOwner::fromString('tenant:AbC')->equals(SessionOwner::fromString('tenant:abc')),
            'Owner values are opaque, so folding case could merge two distinct consumer identities'
        );
    }

    public function test_try_from_string_turns_anything_unusable_into_no_owner(): void
    {
        $this->assertNull(SessionOwner::tryFromString(null));
        $this->assertNull(SessionOwner::tryFromString(42));
        $this->assertNull(SessionOwner::tryFromString(['user:42']));
        $this->assertNull(SessionOwner::tryFromString('42'));
        $this->assertNull(SessionOwner::tryFromString(''));

        $this->assertSame('user:42', SessionOwner::tryFromString('user:42')?->value);
    }
}
