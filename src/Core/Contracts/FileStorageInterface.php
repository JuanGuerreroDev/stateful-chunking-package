<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Core\Contracts;

interface FileStorageInterface
{
    public function storeChunk(string $sessionId, int $chunkIndex, string $content, string $chunkHash): string;
    public function reassembleFile(string $sessionId, string $fileName, int $totalChunks, string $expectedTotalHash): string;
    public function deleteTemporaryChunks(string $sessionId, int $totalChunks): void;
}
