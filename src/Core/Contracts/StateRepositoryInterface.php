<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Core\Contracts;

use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;

/**
 * Interface StateRepositoryInterface
 *
 * Defines the contract for ephemeral chunk session state management.
 * State implementations store upload progress temporarily (e.g. Redis, DB Cache, Memory).
 * Expired sessions are automatically purged and MUST return null.
 */
interface StateRepositoryInterface
{
    /**
     * Persist or update the session state for its remaining TTL duration.
     */
    public function saveSession(ChunkSession $session): void;

    /**
     * Retrieve an active session by its unique ID, or null if expired or missing.
     */
    public function getSession(string $sessionId): ?ChunkSession;

    /**
     * Locate an active session using an idempotent file fingerprint.
     */
    public function findSessionByFingerprint(string $fingerprint): ?ChunkSession;

    /**
     * Atomically update the status of a specific chunk index within the session.
     *
     * When $chunkBytes is provided and the chunk transitions to 'completed' for the
     * first time, the session's cumulative uploaded-byte counter is incremented in
     * the same atomic mutation, so the byte budget cannot be raced.
     */
    public function updateChunkStatus(string $sessionId, int $chunkIndex, string $status, ?int $chunkBytes = null): void;

    /**
     * Run $callback while holding the session's exclusive lock, so lifecycle steps
     * that must not overlap (e.g. reassembly) are serialised per session.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function withSessionLock(string $sessionId, callable $callback): mixed;

    /**
     * Purge a session and its associated fingerprint index immediately.
     */
    public function deleteSession(string $sessionId): void;
}
