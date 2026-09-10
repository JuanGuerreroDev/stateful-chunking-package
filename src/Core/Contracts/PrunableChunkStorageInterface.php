<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Core\Contracts;

/**
 * Capability port for storage adapters that can enumerate their own staging area.
 *
 * Kept separate from {@see FileStorageInterface} on purpose. Sweeping the staging
 * area is a maintenance concern, not part of the upload lifecycle: an adapter that
 * cannot list directories (an object store fronted by a write-only signed URL, say)
 * is still a perfectly valid storage adapter. Folding these methods into the main
 * port would force every consumer with a custom adapter to implement them — and the
 * usual outcome of that is a stub that throws, which is exactly the substitutability
 * failure the segregated interface avoids.
 *
 * Callers must therefore treat this as optional and check for it:
 *
 *     if ($storage instanceof PrunableChunkStorageInterface) { ... }
 *
 * ADR: docs/decisions/0004-staged-chunk-lifecycle-and-garbage-collection.md
 */
interface PrunableChunkStorageInterface
{
    /**
     * Session identifiers whose staging directory has not been written to for at
     * least $olderThanSeconds. A directory holding no files at all counts as stale
     * regardless of age: it carries no bytes worth keeping and no date to judge.
     *
     * Age is the only criterion applied here. Whether a directory may actually be
     * deleted is a question about session state, which this port cannot see and must
     * not guess at — the caller pairs this answer with the state repository.
     *
     * @return array<int, string>
     */
    public function staleChunkDirectories(int $olderThanSeconds): array;

    /**
     * Bytes currently held by one session's staging directory, or 0 if it is absent.
     *
     * Exists so a sweep can report what it reclaimed. A garbage collector that cannot
     * say how much it collected is not auditable, and an unauditable collector is how
     * AF-003 stayed invisible: the command reported success every hour while deleting
     * nothing at all.
     */
    public function chunkDirectorySizeInBytes(string $sessionId): int;
}
