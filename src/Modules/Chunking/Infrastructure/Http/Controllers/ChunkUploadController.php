<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Controllers;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Juanoecr\StatefulChunkingUpload\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\SessionOwner;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\Actions\CancelChunkSessionAction;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\Actions\GetChunkStatusAction;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\Actions\InitiateChunkSessionAction;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\Actions\ReassembleFileAction;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\Actions\UploadChunkAction;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\DTOs\InitiateSessionDTO;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\DTOs\UploadChunkDTO;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\ChunkingException;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\SessionNotFoundException;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\UnauthorizedSessionAccessException;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Contracts\ResolvesCallerIdentity;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Requests\CompleteChunkRequest;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Requests\InitiateChunkRequest;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Requests\UploadChunkRequest;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Responses\ChunkingResponse;
use Psr\Log\LoggerInterface;
use RuntimeException;

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

    private ResolvesCallerIdentity $callerIdentity;

    public function __construct(
        ?StateRepositoryInterface $repository = null,
        ?ResolvesCallerIdentity $callerIdentity = null,
    ) {
        $this->stateRepository = $repository ?? app(StateRepositoryInterface::class);
        $this->callerIdentity = $callerIdentity ?? app(ResolvesCallerIdentity::class);
    }

    private function logger(): LoggerInterface
    {
        $channel = config('stateful-chunking-upload.log_channel');
        $channelName = is_string($channel) ? $channel : null;

        return Log::channel($channelName);
    }

    /**
     * The caller's identity as the domain models it.
     *
     * The port returns a plain string because it is an adapter extension point; the
     * value object is built here, at the boundary, so the domain never holds an
     * identity whose format it could not enforce.
     *
     * A resolver that returns something unusable is a misconfiguration of the host
     * application, not a client error, so it surfaces as a server fault with a message
     * naming the binding to fix. Swallowing it would be worse: an unparseable identity
     * would silently become "no owner", and every session that caller creates would
     * belong to nobody and be unreachable.
     */
    private function resolveCurrentOwnerId(Request $request): SessionOwner
    {
        $resolved = $this->callerIdentity->resolve($request);

        try {
            return SessionOwner::fromString($resolved);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(
                sprintf(
                    'The bound %s returned an unusable caller identity. %s',
                    ResolvesCallerIdentity::class,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }
    }

    /**
     * Canonicalise the session identifier exactly once, at the adapter boundary.
     *
     * Everything downstream — the ownership check, the repository lookup, the Action,
     * and the filesystem paths derived from it — must be handed the same string. The
     * guard used to run against the raw request value while {@see SessionId} lowercased
     * it later, inside the DTO: an upper-case UUID therefore missed the cache on the
     * guarded lookup, so the guard saw null and waved the request through, and the
     * Action then resolved the victim's session anyway. Normalising here, before any
     * authorization decision, is what closes that gap for good.
     *
     * A malformed identifier cannot name a session, so it is answered as a missing one
     * rather than as a 500. In practice the FormRequests and the route patterns already
     * reject those, but this method must not depend on callers having done so.
     *
     * ADR: Normalise caller and session identity at the adapter boundary.
     * See: docs/decisions/0003-normalise-identity-at-the-adapter-boundary.md
     */
    private function canonicalSessionId(mixed $value): string
    {
        $raw = is_string($value) || is_numeric($value) ? (string) $value : '';

        try {
            return SessionId::fromString($raw)->value;
        } catch (InvalidArgumentException $e) {
            throw new SessionNotFoundException(
                'Malformed session identifier rejected at the adapter boundary.',
                ['session_id' => substr($raw, 0, 64)],
                $e
            );
        }
    }

    /**
     * Enforce that the caller owns the session. Raises a domain exception (403) that
     * renders and audits itself, so the HTTP layer never handles the failure inline.
     *
     * Fails closed: a session with no owner belongs to nobody, not to everybody. Only
     * the null-session branch is permissive, and only because the identifier reaching
     * here is already canonical — so null means the session genuinely does not exist,
     * and the Action that follows reports that.
     */
    private function assertSessionOwnership(?ChunkSession $session, Request $request): void
    {
        if ($session === null) {
            return;
        }

        $attemptedBy = $this->resolveCurrentOwnerId($request);

        if (! $session->isOwnedBy($attemptedBy)) {
            throw new UnauthorizedSessionAccessException(
                'Unauthorized attempt to access chunk session (IDOR prevented).',
                [
                    'session_id' => $session->sessionId->value,
                    'session_owner' => $session->ownerId?->value,
                    'attempted_by' => $attemptedBy->value,
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
            'owner_id' => $ownerId->value,
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

        // Canonicalise first, then authorize, then hand the same value downstream —
        // the DTO must not be able to derive a different identifier than the one the
        // ownership check was made against.
        $sessionId = $this->canonicalSessionId($validated['session_id'] ?? null);
        $validated['session_id'] = $sessionId;

        $existingSession = $this->stateRepository->getSession($sessionId);
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

        // Emptiness means "no body arrived", not "the body looks like whitespace".
        // trim() strips NUL, so an all-zero raw-body chunk — ordinary in a sparse file,
        // a disk image or a padded binary — was rejected as empty despite carrying a
        // full payload and a valid chunk_hash (AF-008).
        if ($content === '' && ! $request->hasFile('file')) {
            return ChunkingResponse::inputError('Chunk content cannot be empty', 422);
        }

        $rawChunkSize = config('stateful-chunking-upload.chunk_size_bytes', 2097152);
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
        Request $request,
        string $sessionId,
        GetChunkStatusAction $action
    ): Responsable {
        $canonicalId = $this->canonicalSessionId($sessionId);

        $session = $action->handle($canonicalId);
        $this->assertSessionOwnership($session, $request);

        return ChunkingResponse::sessionStatus($session);
    }

    public function complete(
        CompleteChunkRequest $request,
        ReassembleFileAction $action
    ): Responsable {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $sessionId = $this->canonicalSessionId($validated['session_id'] ?? null);

        $session = $this->stateRepository->getSession($sessionId);
        $this->assertSessionOwnership($session, $request);

        $result = $action->handle($sessionId);

        // Never log the upload_token: it is a bearer credential that resolves the
        // staged file, so it stays out of the audit trail (the rest is safe context).
        $auditResult = $result;
        unset($auditResult['upload_token']);

        $this->logger()->info('File reassembled successfully', [
            'session_id' => $sessionId,
            'result' => $auditResult,
            'ip' => $request->ip(),
        ]);

        return ChunkingResponse::fileReassembled($result);
    }

    public function cancel(
        Request $request,
        string $sessionId,
        CancelChunkSessionAction $action
    ): Responsable {
        $canonicalId = $this->canonicalSessionId($sessionId);

        $session = $this->stateRepository->getSession($canonicalId);
        $this->assertSessionOwnership($session, $request);

        $action->handle($canonicalId);

        $this->logger()->info('Chunk upload session cancelled', [
            'session_id' => $canonicalId,
            'ip' => $request->ip(),
        ]);

        return ChunkingResponse::sessionCancelled();
    }
}
