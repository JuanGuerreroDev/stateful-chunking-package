<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Console\Commands;

use Illuminate\Console\Command;
use Juanoecr\StatefulChunkingUpload\Core\Contracts\FileStorageInterface;
use Juanoecr\StatefulChunkingUpload\Core\Contracts\PrunableChunkStorageInterface;
use Juanoecr\StatefulChunkingUpload\Core\Contracts\StateRepositoryInterface;

final class ClearStaleSessionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stateful-chunking-upload:clear-stale
        {--session= : Specific session ID to clear}
        {--dry-run : Report what would be collected without deleting anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear stale or abandoned chunk upload sessions and their temporary files';

    public function handle(StateRepositoryInterface $repository, FileStorageInterface $storage): int
    {
        $rawSessionId = $this->option('session');
        $sessionId = is_string($rawSessionId) ? $rawSessionId : null;

        if ($sessionId !== null && trim($sessionId) !== '') {
            return $this->clearOne($repository, $storage, $sessionId);
        }

        return $this->sweep($repository, $storage);
    }

    private function clearOne(
        StateRepositoryInterface $repository,
        FileStorageInterface $storage,
        string $sessionId
    ): int {
        $session = $repository->getSession($sessionId);

        if (! $session) {
            $this->warn(sprintf('Session [%s] not found.', $sessionId));

            return Command::SUCCESS;
        }

        if ($this->isDryRun()) {
            $this->line(sprintf('[dry-run] Would clear session [%s].', $session->sessionId->value));

            return Command::SUCCESS;
        }

        $storage->deleteTemporaryChunks($session->sessionId->value, $session->totalChunks);
        $repository->deleteSession($session->sessionId->value);
        $this->info(sprintf('Successfully cleared stale session [%s].', $session->sessionId->value));

        return Command::SUCCESS;
    }

    /**
     * Sweep the staging area for directories left behind by sessions that are gone.
     *
     * Two conditions must hold together, and the second is what makes the sweep safe
     * to schedule aggressively: the directory must be older than the session TTL, and
     * the state repository must have no live session for it. Age alone would collect
     * the chunks of a slow but perfectly healthy upload.
     */
    private function sweep(StateRepositoryInterface $repository, FileStorageInterface $storage): int
    {
        if (! $storage instanceof PrunableChunkStorageInterface) {
            $this->warn(sprintf(
                'The configured storage adapter [%s] cannot enumerate its staging area, so nothing was swept. '
                .'Implement %s to enable garbage collection, or clear sessions individually with --session.',
                $storage::class,
                PrunableChunkStorageInterface::class
            ));

            return Command::SUCCESS;
        }

        $ttl = $this->sessionTtl();
        $candidates = $storage->staleChunkDirectories($ttl);

        $collected = 0;
        $skipped = 0;
        $bytes = 0;
        $dryRun = $this->isDryRun();

        foreach ($candidates as $sessionId) {
            // A live session still owns its chunks, however old the last write is.
            if ($repository->getSession($sessionId) !== null) {
                $skipped++;

                continue;
            }

            $size = $storage->chunkDirectorySizeInBytes($sessionId);

            if (! $dryRun) {
                // totalChunks is unknown here — the session state is already gone.
                // deleteTemporaryChunks removes the whole directory, so 0 is honest.
                $storage->deleteTemporaryChunks($sessionId, 0);
            }

            $collected++;
            $bytes += $size;
        }

        $this->info(sprintf(
            '%s%d abandoned staging %s (%s) %s. %d skipped as still live.',
            $dryRun ? '[dry-run] ' : '',
            $collected,
            $collected === 1 ? 'directory' : 'directories',
            $this->humanBytes($bytes),
            $dryRun ? 'would be collected' : 'collected',
            $skipped
        ));

        return Command::SUCCESS;
    }

    private function isDryRun(): bool
    {
        return (bool) $this->option('dry-run');
    }

    private function sessionTtl(): int
    {
        $raw = config('stateful-chunking-upload.session_ttl', 21600);

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : 21600;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.2f %s', $value, $units[$unit]);
    }
}
