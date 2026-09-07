<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions\CancelChunkSessionAction;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions\GetChunkStatusAction;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions\InitiateChunkSessionAction;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions\ReassembleFileAction;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions\UploadChunkAction;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\DTOs\InitiateSessionDTO;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\DTOs\UploadChunkDTO;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions\ChunkingException;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions\UnauthorizedSessionAccessException;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\Requests\InitiateChunkRequest;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\Requests\UploadChunkRequest;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\Responses\ChunkingResponse;
use Psr\Log\LoggerInterface;

/**
 * HTTP adapter for the chunking lifecycle.
 *
 * By design this controller does NOT catch or translate domain exceptions. Every
 * expected failure is a {@see ChunkingException},
 * which renders itself (correct status + safe message) and audits itself via its
 * report() hook; anything unexpected falls through to Laravel's global handler,
 * which sanitises the response in production. Keeping error translation out of the
 * HTTP layer is what makes this a thin adapter over the application Actions.
 *
 * The only inline guards here are input-shape checks (empty / oversized payload),
 * which are request concerns, not domain failures.
 */
final class ChunkUploadController extends Controller
{
    private StateRepositoryInterface $stateRepository;

    public function __construct(?StateRepositoryInterface $repository = null)
    {
        $this->stateRepository = $repository ?? app(StateRepositoryInterface::class);
    }

    private function logger(): LoggerInterface
    {
        $channel = config('stateful-chunking.log_channel');
        $channelName = is_string($channel) ? $channel : null;

        return Log::channel($channelName);
    }

    private function resolveCurrentOwnerId(Request $request): string
    {
        $user = $request->user();
        if ($user instanceof Authenticatable) {
            $authId = $user->getAuthIdentifier();
            if ((is_string($authId) || is_int($authId)) && (string) $authId !== '') {
                return 'user:'.(string) $authId;
            }
        }

        $ip = $request->ip() ?: '127.0.0.1';

        return 'ip:'.$ip;
    }

    /**
     * Enforce that the caller owns the session. Raises a domain exception (403) that
     * renders and audits itself, so the HTTP layer never handles the failure inline.
     */
    private function assertSessionOwnership(?ChunkSession $session, Request $request): void
    {
        if ($session === null) {
            return;
        }

        $attemptedBy = $this->resolveCurrentOwnerId($request);

        if ($session->ownerId !== null && $session->ownerId !== $attemptedBy) {
            throw new UnauthorizedSessionAccessException(
                'Unauthorized attempt to access chunk session (IDOR prevented).',
                [
                    'session_id' => $session->sessionId->value,
                    'session_owner' => $session->ownerId,
                    'attempted_by' => $attemptedBy,
                    'ip' => $request->ip(),
                ]
            );
        }
    }

    public function initiate(
        InitiateChunkRequest $request,
        InitiateChunkSessionAction $action
    ): Responsable {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $ownerId = $this->resolveCurrentOwnerId($request);
        $dto = InitiateSessionDTO::fromArray($validated, $ownerId);
        $session = $action->handle($dto);

        $this->logger()->info('Chunk upload session initiated', [
            'session_id' => $session->sessionId->value,
            'file_name' => $session->fileName,
            'file_size' => $session->fileSize,
            'total_chunks' => $session->totalChunks,
            'owner_id' => $ownerId,
            'ip' => $request->ip(),
        ]);

        return ChunkingResponse::sessionInitiated($session);
    }

    public function upload(
        UploadChunkRequest $request,
        UploadChunkAction $action
    ): Responsable {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $rawSessionId = isset($validated['session_id']) && (is_string($validated['session_id']) || is_numeric($validated['session_id'])) ? (string) $validated['session_id'] : '';

        $existingSession = $this->stateRepository->getSession($rawSessionId);
        $this->assertSessionOwnership($existingSession, $request);

        $content = '';
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            if ($file instanceof UploadedFile) {
                $content = (string) file_get_contents($file->getRealPath());
            }
        } elseif ($request->has('file') && is_string($request->input('file'))) {
            $content = (string) $request->input('file');
        } else {
            $content = (string) $request->getContent();
        }

        if (trim($content) === '' && ! $request->hasFile('file')) {
            return ChunkingResponse::inputError('Chunk content cannot be empty', 422);
        }

        $rawChunkSize = config('stateful-chunking.chunk_size_bytes', 2097152);
        $chunkSizeBytes = is_numeric($rawChunkSize) && (int) $rawChunkSize > 0 ? (int) $rawChunkSize : 2097152;
        $maxAllowedBytes = (int) ($chunkSizeBytes * 1.1);

        if (strlen($content) > $maxAllowedBytes) {
            return ChunkingResponse::inputError(
                sprintf(
                    'Chunk payload size (%d bytes) exceeds maximum allowed limit (%d bytes).',
                    strlen($content),
                    $maxAllowedBytes
                ),
                413
            );
        }

        $dto = UploadChunkDTO::fromArray($validated, $content);
        $session = $action->handle($dto);

        return ChunkingResponse::chunkUploaded($session, $dto->chunkIndex);
    }

    public function status(
        string $sessionId,
        GetChunkStatusAction $action
    ): Responsable {
        $session = $action->handle($sessionId);
        $this->assertSessionOwnership($session, request());

        return ChunkingResponse::sessionStatus($session);
    }

    public function complete(
        Request $request,
        ReassembleFileAction $action
    ): Responsable {
        $request->validate(['session_id' => 'required|string']);

        $rawSessionId = $request->input('session_id');
        $sessionId = is_string($rawSessionId) || is_numeric($rawSessionId) ? (string) $rawSessionId : '';

        $session = $this->stateRepository->getSession($sessionId);
        $this->assertSessionOwnership($session, $request);

        $result = $action->handle($sessionId);

        $this->logger()->info('File reassembled successfully', [
            'session_id' => $sessionId,
            'result' => $result,
            'ip' => $request->ip(),
        ]);

        return ChunkingResponse::fileReassembled($result);
    }

    public function cancel(
        string $sessionId,
        CancelChunkSessionAction $action
    ): Responsable {
        $session = $this->stateRepository->getSession($sessionId);
        $this->assertSessionOwnership($session, request());

        $action->handle($sessionId);

        $this->logger()->info('Chunk upload session cancelled', [
            'session_id' => $sessionId,
            'ip' => request()->ip(),
        ]);

        return ChunkingResponse::sessionCancelled();
    }
}
