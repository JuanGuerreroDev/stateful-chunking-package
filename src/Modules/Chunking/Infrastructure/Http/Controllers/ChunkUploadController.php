<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use StatefulChunking\LaravelPackage\Modules\Chunking\Application\Actions\InitiateChunkSessionAction;
use StatefulChunking\LaravelPackage\Modules\Chunking\Application\Actions\UploadChunkAction;
use StatefulChunking\LaravelPackage\Modules\Chunking\Application\Actions\GetChunkStatusAction;
use StatefulChunking\LaravelPackage\Modules\Chunking\Application\Actions\ReassembleFileAction;
use StatefulChunking\LaravelPackage\Modules\Chunking\Application\Actions\CancelChunkSessionAction;
use StatefulChunking\LaravelPackage\Modules\Chunking\Application\DTOs\InitiateSessionDTO;
use StatefulChunking\LaravelPackage\Modules\Chunking\Application\DTOs\UploadChunkDTO;
use StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Http\Requests\InitiateChunkRequest;
use StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Http\Requests\UploadChunkRequest;
use Exception;

final class ChunkUploadController extends Controller
{
    private function logger()
    {
        $channel = config('stateful-chunking.log_channel');
        return Log::channel($channel);
    }

    public function initiate(
        InitiateChunkRequest $request,
        InitiateChunkSessionAction $action
    ): JsonResponse {
        $dto = InitiateSessionDTO::fromArray($request->validated());
        $session = $action->handle($dto);

        $this->logger()->info('Chunk upload session initiated', [
            'session_id' => $session->sessionId->value,
            'file_name' => $session->fileName,
            'file_size' => $session->fileSize,
            'total_chunks' => $session->totalChunks,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Session initiated successfully',
            'data' => $session->toArray(),
        ], 201);
    }

    public function upload(
        UploadChunkRequest $request,
        UploadChunkAction $action
    ): JsonResponse {
        $validated = $request->validated();
        
        $content = '';
        if ($request->hasFile('file')) {
            $content = (string) file_get_contents($request->file('file')->getRealPath());
        } elseif ($request->has('file') && is_string($request->input('file'))) {
            $content = (string) $request->input('file');
        } else {
            $content = (string) $request->getContent();
        }

        if (trim($content) === '' && !$request->hasFile('file')) {
            return response()->json(['message' => 'Chunk content cannot be empty'], 422);
        }

        try {
            $dto = UploadChunkDTO::fromArray($validated, $content);
            $session = $action->handle($dto);

            return response()->json([
                'message' => sprintf('Chunk %d uploaded successfully', $dto->chunkIndex),
                'data' => $session->toArray(),
            ], 200);
        } catch (Exception $e) {
            $this->logger()->warning('Chunk upload failed', [
                'session_id' => $validated['session_id'] ?? null,
                'chunk_index' => $validated['chunk_index'] ?? null,
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
            return response()->json([
                'data' => $session->toArray(),
            ], 200);
        } catch (Exception $e) {
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

        try {
            $result = $action->handle((string) $request->input('session_id'));

            $this->logger()->info('File reassembled successfully', [
                'session_id' => $request->input('session_id'),
                'result' => $result,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'File reassembled successfully',
                'data' => $result,
            ], 200);
        } catch (Exception $e) {
            $this->logger()->error('File reassembly failed', [
                'session_id' => $request->input('session_id'),
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
        $action->handle($sessionId);

        $this->logger()->info('Chunk upload session cancelled', [
            'session_id' => $sessionId,
            'ip' => request()->ip(),
        ]);

        return response()->json([
            'message' => 'Session cancelled and resources purged',
        ], 200);
    }
}
