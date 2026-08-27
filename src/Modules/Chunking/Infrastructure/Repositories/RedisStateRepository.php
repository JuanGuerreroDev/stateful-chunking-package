<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Repositories;

use Illuminate\Support\Facades\Redis;
use StatefulChunking\LaravelPackage\Core\Contracts\StateRepositoryInterface;
use StatefulChunking\LaravelPackage\Core\ValueObjects\SessionId;
use StatefulChunking\LaravelPackage\Core\ValueObjects\ChunkHash;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Entities\ChunkSession;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Enums\SessionStatus;

final class RedisStateRepository implements StateRepositoryInterface
{
    private function getTtl(): int
    {
        return (int) config('stateful-chunking.redis_session_ttl', 21600);
    }

    private function getConnectionName(): ?string
    {
        return config('stateful-chunking.redis_connection', 'default');
    }

    private function metaKey(string $sessionId): string
    {
        return sprintf('chunk_session:%s:meta', $sessionId);
    }

    private function chunksKey(string $sessionId): string
    {
        return sprintf('chunk_session:%s:chunks', $sessionId);
    }

    private function fingerprintKey(string $fingerprint): string
    {
        return sprintf('chunk_fingerprint:%s', $fingerprint);
    }

    public function saveSession(ChunkSession $session): void
    {
        $redis = Redis::connection($this->getConnectionName());
        $ttl = $this->getTtl();

        $metaData = [
            'session_id' => $session->sessionId->value,
            'file_name' => $session->fileName,
            'file_size' => $session->fileSize,
            'total_chunks' => $session->totalChunks,
            'total_hash' => $session->totalHash->value,
            'fingerprint' => $session->fingerprint,
            'status' => $session->status->value,
        ];

        $redis->setex($this->metaKey($session->sessionId->value), $ttl, json_encode($metaData));

        if (!empty($session->fingerprint)) {
            $redis->setex($this->fingerprintKey($session->fingerprint), $ttl, $session->sessionId->value);
        }

        if (!empty($session->chunksMap)) {
            $formattedMap = [];
            foreach ($session->chunksMap as $idx => $st) {
                $formattedMap[(string) $idx] = (string) $st;
            }
            $redis->hmset($this->chunksKey($session->sessionId->value), $formattedMap);
            $redis->expire($this->chunksKey($session->sessionId->value), $ttl);
        }
    }

    public function getSession(string $sessionId): ?ChunkSession
    {
        $redis = Redis::connection($this->getConnectionName());
        $metaJson = $redis->get($this->metaKey($sessionId));

        if (!$metaJson) {
            return null;
        }

        $meta = json_decode((string) $metaJson, true);
        if (!is_array($meta)) {
            return null;
        }

        $chunksRaw = $redis->hgetall($this->chunksKey($sessionId)) ?? [];
        $chunksMap = [];
        foreach ($chunksRaw as $idx => $st) {
            $chunksMap[(int) $idx] = (string) $st;
        }

        return new ChunkSession(
            sessionId: SessionId::fromString($meta['session_id']),
            fileName: $meta['file_name'],
            fileSize: (int) $meta['file_size'],
            totalChunks: (int) $meta['total_chunks'],
            totalHash: ChunkHash::fromString($meta['total_hash']),
            fingerprint: $meta['fingerprint'] ?? '',
            status: SessionStatus::from($meta['status'] ?? 'pending'),
            chunksMap: $chunksMap
        );
    }

    public function findSessionByFingerprint(string $fingerprint): ?ChunkSession
    {
        if (trim($fingerprint) === '') {
            return null;
        }

        $redis = Redis::connection($this->getConnectionName());
        $sessionId = $redis->get($this->fingerprintKey($fingerprint));

        if (!$sessionId) {
            return null;
        }

        return $this->getSession((string) $sessionId);
    }

    public function updateChunkStatus(string $sessionId, int $chunkIndex, string $status): void
    {
        $redis = Redis::connection($this->getConnectionName());
        $ttl = $this->getTtl();

        $redis->hset($this->chunksKey($sessionId), (string) $chunkIndex, $status);
        $redis->expire($this->chunksKey($sessionId), $ttl);
        $redis->expire($this->metaKey($sessionId), $ttl);

        // Update overall session status if all completed
        $session = $this->getSession($sessionId);
        if ($session) {
            $session->markChunkCompleted($chunkIndex);
            $redis->setex($this->metaKey($sessionId), $ttl, json_encode([
                'session_id' => $session->sessionId->value,
                'file_name' => $session->fileName,
                'file_size' => $session->fileSize,
                'total_chunks' => $session->totalChunks,
                'total_hash' => $session->totalHash->value,
                'fingerprint' => $session->fingerprint,
                'status' => $session->status->value,
            ]));
        }
    }

    public function deleteSession(string $sessionId): void
    {
        $redis = Redis::connection($this->getConnectionName());

        $session = $this->getSession($sessionId);
        if ($session && !empty($session->fingerprint)) {
            $redis->del($this->fingerprintKey($session->fingerprint));
        }

        $redis->del($this->metaKey($sessionId));
        $redis->del($this->chunksKey($sessionId));
    }
}
