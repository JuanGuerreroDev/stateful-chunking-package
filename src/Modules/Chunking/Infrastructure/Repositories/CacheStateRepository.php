<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Repositories;

use Illuminate\Support\Facades\Cache;
use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Enums\SessionStatus;

final class CacheStateRepository implements StateRepositoryInterface
{
    private function getTtl(): int
    {
        $ttl = config('stateful-chunking.session_ttl', 21600);
        return is_numeric($ttl) ? (int) $ttl : 21600;
    }

    private function getStoreName(): ?string
    {
        $store = config('stateful-chunking.cache_store')
            ?: config('stateful-chunking.driver')
            ?: config('cache.default');
        return is_string($store) ? $store : null;
    }

    private function sessionKey(string $sessionId): string
    {
        return sprintf('chunk_session:%s', $sessionId);
    }

    private function lockKey(string $sessionId): string
    {
        return sprintf('chunk_session_lock:%s', $sessionId);
    }

    private function fingerprintKey(string $fingerprint): string
    {
        return sprintf('chunk_fingerprint:%s', $fingerprint);
    }

    public function saveSession(ChunkSession $session): void
    {
        $store = Cache::store($this->getStoreName());
        $ttl = $this->getTtl();

        $sessionData = [
            'session_id' => $session->sessionId->value,
            'file_name' => $session->fileName,
            'file_size' => $session->fileSize,
            'total_chunks' => $session->totalChunks,
            'total_hash' => $session->totalHash->value,
            'fingerprint' => $session->fingerprint,
            'status' => $session->status->value,
            'chunks_map' => $session->chunksMap,
            'created_at' => time(),
        ];

        $store->put($this->sessionKey($session->sessionId->value), $sessionData, $ttl);

        if (!empty($session->fingerprint)) {
            $store->put($this->fingerprintKey($session->fingerprint), $session->sessionId->value, $ttl);
        }
    }

    public function getSession(string $sessionId): ?ChunkSession
    {
        $store = Cache::store($this->getStoreName());
        $sessionData = $store->get($this->sessionKey($sessionId));

        if (!is_array($sessionData)) {
            return null;
        }

        $chunksMap = [];
        if (isset($sessionData['chunks_map']) && is_array($sessionData['chunks_map'])) {
            foreach ($sessionData['chunks_map'] as $idx => $st) {
                $chunksMap[(int) $idx] = is_string($st) || is_numeric($st) ? (string) $st : 'pending';
            }
        }

        $rawSessionId = isset($sessionData['session_id']) && is_string($sessionData['session_id']) ? $sessionData['session_id'] : '';
        $rawFileName = isset($sessionData['file_name']) && is_string($sessionData['file_name']) ? $sessionData['file_name'] : '';
        $rawFileSize = isset($sessionData['file_size']) && is_numeric($sessionData['file_size']) ? (int) $sessionData['file_size'] : 0;
        $rawTotalChunks = isset($sessionData['total_chunks']) && is_numeric($sessionData['total_chunks']) ? (int) $sessionData['total_chunks'] : 0;
        $rawTotalHash = isset($sessionData['total_hash']) && is_string($sessionData['total_hash']) ? $sessionData['total_hash'] : '';
        $rawFingerprint = isset($sessionData['fingerprint']) && is_string($sessionData['fingerprint']) ? $sessionData['fingerprint'] : '';
        $rawStatus = isset($sessionData['status']) && (is_string($sessionData['status']) || is_int($sessionData['status'])) ? $sessionData['status'] : 'pending';

        return new ChunkSession(
            sessionId: SessionId::fromString($rawSessionId),
            fileName: $rawFileName,
            fileSize: $rawFileSize,
            totalChunks: $rawTotalChunks,
            totalHash: ChunkHash::fromString($rawTotalHash),
            fingerprint: $rawFingerprint,
            status: SessionStatus::from($rawStatus),
            chunksMap: $chunksMap
        );
    }

    public function findSessionByFingerprint(string $fingerprint): ?ChunkSession
    {
        if (trim($fingerprint) === '') {
            return null;
        }

        $store = Cache::store($this->getStoreName());
        $sessionId = $store->get($this->fingerprintKey($fingerprint));

        if (!is_string($sessionId) && !is_numeric($sessionId)) {
            return null;
        }

        return $this->getSession((string) $sessionId);
    }

    public function updateChunkStatus(string $sessionId, int $chunkIndex, string $status): void
    {
        $store = Cache::store($this->getStoreName());

        $mutate = function () use ($sessionId, $chunkIndex, $status): void {
            $session = $this->getSession($sessionId);
            if (!$session) {
                return;
            }

            if ($status === 'completed') {
                $session->markChunkCompleted($chunkIndex);
            } else {
                $session->chunksMap[$chunkIndex] = $status;
            }

            $this->saveSession($session);
        };

        if ($store->getStore() instanceof \Illuminate\Contracts\Cache\LockProvider) {
            /** @var \Illuminate\Cache\Repository&\Illuminate\Contracts\Cache\LockProvider $storeWithLock */
            $storeWithLock = $store;
            $storeWithLock->lock($this->lockKey($sessionId), 10)->block(5, $mutate);
        } else {
            $mutate();
        }
    }

    public function deleteSession(string $sessionId): void
    {
        $store = Cache::store($this->getStoreName());
        $session = $this->getSession($sessionId);

        if ($session && !empty($session->fingerprint)) {
            $store->forget($this->fingerprintKey($session->fingerprint));
        }

        $store->forget($this->sessionKey($sessionId));
    }
}
