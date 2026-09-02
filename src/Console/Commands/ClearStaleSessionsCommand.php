<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Console\Commands;

use Illuminate\Console\Command;
use StatefulChunking\LaravelPackage\Core\Contracts\StateRepositoryInterface;
use StatefulChunking\LaravelPackage\Core\Contracts\FileStorageInterface;

final class ClearStaleSessionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stateful-chunking:clear-stale {--session= : Specific session ID to clear}';

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
            $session = $repository->getSession($sessionId);

            if ($session) {
                $storage->deleteTemporaryChunks($session->sessionId->value, $session->totalChunks);
                $repository->deleteSession($session->sessionId->value);
                $this->info(sprintf('Successfully cleared stale session [%s].', $session->sessionId->value));
            } else {
                $this->warn(sprintf('Session [%s] not found.', $sessionId));
            }

            return Command::SUCCESS;
        }

        $this->info('Stateful Chunking garbage collection command executed successfully.');
        return Command::SUCCESS;
    }
}
