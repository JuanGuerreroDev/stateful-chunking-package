<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Minimal, dependency-free JSON snapshot assertion for the HTTP response contract.
 *
 * Unlike field-by-field assertions (which only watch the keys you name), a
 * snapshot freezes the WHOLE payload: any field that appears, disappears, or
 * changes shape breaks the test. That is exactly the guard the response envelope
 * needs — a stray `owner_id` or server `path` sneaking back into a response would
 * fail here even though no test explicitly looks for it.
 *
 * Volatile values (ids, timestamps, the encrypted token) are masked before
 * comparison so the snapshot pins structure and stable values without being
 * flaky. To (re)generate snapshots after an intentional contract change, run the
 * suite with `UPDATE_SNAPSHOTS=1` and review the diff.
 */
final class Snapshot
{
    /**
     * Response keys whose values are non-deterministic between runs. Masked to a
     * placeholder so the snapshot asserts the key's PRESENCE and shape, not its
     * volatile value.
     *
     * @var array<string, string>
     */
    private const VOLATILE = [
        'session_id' => '<session_id>',
        'created_at' => '<timestamp>',
        'expires_at' => '<timestamp>',
        'remaining_ttl' => '<remaining_ttl>',
        'upload_token' => '<upload_token>',
        'path' => '<server_path>',
        'relative_path' => '<server_path>',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function assertMatches(string $name, array $payload): void
    {
        $actual = self::encode(self::normalize($payload));
        $file = self::path($name);
        $update = filter_var(getenv('UPDATE_SNAPSHOTS'), FILTER_VALIDATE_BOOL);

        if ($update || ! is_file($file)) {
            // A missing snapshot on CI means it was never committed: fail loudly
            // instead of silently minting one that would rubber-stamp any output.
            if (! $update && getenv('CI') !== false) {
                Assert::fail("Missing snapshot [{$name}]. Generate it with UPDATE_SNAPSHOTS=1 and commit tests/snapshots/{$name}.json.");
            }

            $dir = dirname($file);
            if (! is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }
            file_put_contents($file, $actual."\n");

            Assert::markTestSkipped("Snapshot [{$name}] written; re-run without UPDATE_SNAPSHOTS to assert against it.");
        }

        $expected = rtrim((string) file_get_contents($file), "\n");

        Assert::assertSame(
            $expected,
            $actual,
            "Response contract drifted from snapshot [{$name}]. If this change is intentional, regenerate with UPDATE_SNAPSHOTS=1 and review the diff."
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalize(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && array_key_exists($key, self::VOLATILE)) {
                $out[$key] = self::VOLATILE[$key];
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $out[$key] = self::normalize($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function path(string $name): string
    {
        return dirname(__DIR__).'/snapshots/'.$name.'.json';
    }
}
