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
     */
    public function updateChunkStatus(string $sessionId, int $chunkIndex, string $status): void;

    /**
     * Purge a session and its associated fingerprint index immediately.
     */
    public function deleteSession(string $sessionId): void;
}
