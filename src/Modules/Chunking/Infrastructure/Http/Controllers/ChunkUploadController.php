<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function initiate(
        InitiateChunkRequest $request,
        InitiateChunkSessionAction $action
    ): JsonResponse {
        $dto = InitiateSessionDTO::fromArray($request->validated());
        $session = $action->handle($dto);

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
            return response()->json([
                'message' => $e->getMessage(),
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
            return response()->json([
                'message' => $e->getMessage(),
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
            return response()->json([
                'message' => 'File reassembled successfully',
                'data' => $result,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function cancel(
        string $sessionId,
        CancelChunkSessionAction $action
    ): JsonResponse {
        $action->handle($sessionId);
        return response()->json([
            'message' => 'Session cancelled and resources purged',
        ], 200);
    }
}
