<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Core\Contracts;

use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events\ChunkSessionExpired;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions\UploadBudgetExceededException;

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
     *
     * This read is not free of side effects, and never has been: finding an expired
     * session purges it. Implementations MUST also announce that purge by dispatching
     * {@see ChunkSessionExpired}, because the caller has no other way to learn that a
     * staging directory was just orphaned. Silent expiry is what let abandoned chunks
     * accumulate forever (AF-003).
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
     * the same atomic mutation.
     *
     * When $byteBudget is also provided, the budget is re-verified against the state
     * read *inside* the critical section, and {@see UploadBudgetExceededException} is
     * thrown before any mutation if it would be exceeded. Verifying it outside is
     * a check-then-act race: N concurrent uploads of distinct chunk indices each read
     * the same uploadedBytes snapshot and each pass, overshooting the budget by up to
     * (N-1) chunks (AF-007). This docblock previously claimed the budget "cannot be
     * raced" while nothing in the lock checked it — the claim is now the reason the
     * parameter exists.
     *
     * $byteBudget is optional so that implementations written against the previous
     * signature keep satisfying this contract.
     */
    public function updateChunkStatus(
        string $sessionId,
        int $chunkIndex,
        string $status,
        ?int $chunkBytes = null,
        ?int $byteBudget = null
    ): void;

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
