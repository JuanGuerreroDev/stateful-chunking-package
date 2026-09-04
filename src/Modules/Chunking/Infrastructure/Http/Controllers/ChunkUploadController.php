<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions\InitiateChunkSessionAction;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions\UploadChunkAction;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions\GetChunkStatusAction;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions\ReassembleFileAction;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions\CancelChunkSessionAction;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\DTOs\InitiateSessionDTO;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\DTOs\UploadChunkDTO;
use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\Requests\InitiateChunkRequest;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\Requests\UploadChunkRequest;
use Throwable;

final class ChunkUploadController extends Controller
{
    private StateRepositoryInterface $stateRepository;

    public function __construct(?StateRepositoryInterface $repository = null)
    {
        $this->stateRepository = $repository ?? app(StateRepositoryInterface::class);
    }

    private function logger(): \Psr\Log\LoggerInterface
    {
        $channel = config('stateful-chunking.log_channel');
        $channelName = is_string($channel) ? $channel : null;
        return Log::channel($channelName);
    }

    private function resolveCurrentOwnerId(Request $request): string
    {
        $user = $request->user();
        if ($user instanceof \Illuminate\Contracts\Auth\Authenticatable) {
            $authId = $user->getAuthIdentifier();
            if ((is_string($authId) || is_int($authId)) && (string) $authId !== '') {
                return 'user:' . (string) $authId;
            }
        }

        $ip = $request->ip() ?: '127.0.0.1';
        return 'ip:' . $ip;
    }

    private function verifySessionOwnership(?ChunkSession $session, Request $request): ?JsonResponse
    {
        if ($session === null) {
            return null;
        }

        if ($session->ownerId !== null && $session->ownerId !== $this->resolveCurrentOwnerId($request)) {
            $this->logger()->warning('Unauthorized attempt to access chunk session (IDOR prevented)', [
                'session_id' => $session->sessionId->value,
                'session_owner' => $session->ownerId,
                'attempted_by' => $this->resolveCurrentOwnerId($request),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Unauthorized action on chunk session.',
            ], 403);
        }

        return null;
    }

    public function initiate(
        InitiateChunkRequest $request,
        InitiateChunkSessionAction $action
    ): JsonResponse {
        try {
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

            return response()->json([
                'message' => 'Session initiated successfully',
                'data' => $session->toArray(),
            ], 201);
        } catch (\Throwable $e) {
            $this->logger()->error('Chunk upload session initiation failed', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Upload session initiation failed. Please try again.',
            ], 500);
        }
    }

    public function upload(
        UploadChunkRequest $request,
        UploadChunkAction $action
    ): JsonResponse {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $rawSessionId = isset($validated['session_id']) && (is_string($validated['session_id']) || is_numeric($validated['session_id'])) ? (string) $validated['session_id'] : '';
        $chunkIndex = isset($validated['chunk_index']) && (is_int($validated['chunk_index']) || is_string($validated['chunk_index'])) ? $validated['chunk_index'] : null;

        try {
            $existingSession = $this->stateRepository->getSession($rawSessionId);
            if ($authError = $this->verifySessionOwnership($existingSession, $request)) {
                return $authError;
            }
            
            $content = '';
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                if ($file instanceof \Illuminate\Http\UploadedFile) {
                    $content = (string) file_get_contents($file->getRealPath());
                }
            } elseif ($request->has('file') && is_string($request->input('file'))) {
                $content = (string) $request->input('file');
            } else {
                $content = (string) $request->getContent();
            }

            if (trim($content) === '' && !$request->hasFile('file')) {
                return response()->json(['message' => 'Chunk content cannot be empty'], 422);
            }

            $rawChunkSize = config('stateful-chunking.chunk_size_bytes', 2097152);
            $chunkSizeBytes = is_numeric($rawChunkSize) && (int) $rawChunkSize > 0 ? (int) $rawChunkSize : 2097152;
            $maxAllowedBytes = (int) ($chunkSizeBytes * 1.1);

            if (strlen($content) > $maxAllowedBytes) {
                return response()->json([
                    'message' => sprintf(
                        'Chunk payload size (%d bytes) exceeds maximum allowed limit (%d bytes).',
                        strlen($content),
                        $maxAllowedBytes
                    ),
                ], 413);
            }

            $dto = UploadChunkDTO::fromArray($validated, $content);
            $session = $action->handle($dto);

            return response()->json([
                'message' => sprintf('Chunk %d uploaded successfully', $dto->chunkIndex),
                'data' => $session->toArray(),
            ], 200);
        } catch (\Throwable $e) {
            $this->logger()->warning('Chunk upload failed', [
                'session_id' => $rawSessionId,
                'chunk_index' => $chunkIndex,
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Chunk upload failed. Please try again.',
            ], 400);
        }
    }

    public function status(
        string $sessionId,
        GetChunkStatusAction $action
    ): JsonResponse {
        try {
            $session = $action->handle($sessionId);
            if ($authError = $this->verifySessionOwnership($session, request())) {
                return $authError;
            }

            return response()->json([
                'data' => $session->toArray(),
            ], 200);
        } catch (\Throwable $e) {
            $this->logger()->info('Chunk status check failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'ip' => request()->ip(),
            ]);

            return response()->json([
                'message' => 'Session status not found.',
            ], 404);
        }
    }

    public function complete(
        Request $request,
        ReassembleFileAction $action
    ): JsonResponse {
        $request->validate(['session_id' => 'required|string']);

        $rawSessionId = $request->input('session_id');
        $sessionId = is_string($rawSessionId) || is_numeric($rawSessionId) ? (string) $rawSessionId : '';

        try {
            $session = $this->stateRepository->getSession($sessionId);
            if ($authError = $this->verifySessionOwnership($session, $request)) {
                return $authError;
            }

            $result = $action->handle($sessionId);

            $this->logger()->info('File reassembled successfully', [
                'session_id' => $sessionId,
                'result' => $result,
                'ip' => $request->ip(),
            ]);

            $responseData = $result;
            if (!config('stateful-chunking.expose_server_paths', false)) {
                unset($responseData['path'], $responseData['relative_path']);
            }

            return response()->json([
                'message' => 'File reassembled successfully',
                'data' => $responseData,
            ], 200);
        } catch (\Throwable $e) {
            $this->logger()->error('File reassembly failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'File reassembly processing failed. Please try again.',
            ], 400);
        }
    }

    public function cancel(
        string $sessionId,
        CancelChunkSessionAction $action
    ): JsonResponse {
        try {
            $session = $this->stateRepository->getSession($sessionId);
            if ($authError = $this->verifySessionOwnership($session, request())) {
                return $authError;
            }

            $action->handle($sessionId);

            $this->logger()->info('Chunk upload session cancelled', [
                'session_id' => $sessionId,
                'ip' => request()->ip(),
            ]);

            return response()->json([
                'message' => 'Session cancelled and resources purged',
            ], 200);
        } catch (\Throwable $e) {
            $this->logger()->error('Chunk upload session cancellation failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'ip' => request()->ip(),
            ]);

            return response()->json([
                'message' => 'Session cancellation failed. Please try again.',
            ], 500);
        }
    }
}
