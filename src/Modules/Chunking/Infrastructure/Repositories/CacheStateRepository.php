<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Repositories;

use Illuminate\Support\Facades\Cache;
use StatefulChunking\LaravelPackage\Core\Contracts\StateRepositoryInterface;
use StatefulChunking\LaravelPackage\Core\ValueObjects\SessionId;
use StatefulChunking\LaravelPackage\Core\ValueObjects\ChunkHash;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Entities\ChunkSession;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Enums\SessionStatus;

final class CacheStateRepository implements StateRepositoryInterface
{
    private function getTtl(): int
    {
        return (int) config('stateful-chunking.redis_session_ttl', 21600);
    }

    private function getStoreName(): ?string
    {
        return config('stateful-chunking.cache_store') ?: config('cache.default');
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
                $chunksMap[(int) $idx] = (string) $st;
            }
        }

        return new ChunkSession(
            sessionId: SessionId::fromString($sessionData['session_id']),
            fileName: $sessionData['file_name'],
            fileSize: (int) $sessionData['file_size'],
            totalChunks: (int) $sessionData['total_chunks'],
            totalHash: ChunkHash::fromString($sessionData['total_hash']),
            fingerprint: $sessionData['fingerprint'] ?? '',
            status: SessionStatus::from($sessionData['status'] ?? 'pending'),
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

        if (!$sessionId) {
            return null;
        }

        return $this->getSession((string) $sessionId);
    }

    public function updateChunkStatus(string $sessionId, int $chunkIndex, string $status): void
    {
        $store = Cache::store($this->getStoreName());
        $lock = $store->lock($this->lockKey($sessionId), 10);

        $lock->block(5, function () use ($sessionId, $chunkIndex, $status) {
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
        });
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
