<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Base type for every failure the chunking domain can raise.
 *
 * The point of this hierarchy is to let the domain (and the ports it owns, such
 * as the storage adapter) express *what went wrong* in its own vocabulary, so the
 * HTTP layer never has to catch and translate exceptions. Each subclass declares:
 *
 *  - a stable HTTP status ({@see statusCode()}), so the framework maps it correctly;
 *  - a client-safe public message ({@see $publicMessage}) that never leaks internals;
 *  - a log level and structured context, so operators can audit the failure.
 *
 * Because it implements {@see Responsable}, Laravel's exception handler renders it
 * automatically via {@see toResponse()}; because it exposes {@see report()}, the
 * handler audits it automatically. The controller therefore carries no try/catch:
 * expected failures render themselves, and anything unexpected falls through to the
 * framework's global handler (which sanitises the response in production).
 *
 * Security note: the detailed ({@see getMessage()}) message and the context are
 * server-side only — they feed the log, never the response. The response body
 * carries the generic {@see $publicMessage} alone.
 */
abstract class ChunkingException extends RuntimeException implements Responsable
{
    /** HTTP status this failure maps to. Overridden per subclass. */
    protected int $statusCode = 400;

    /** Client-safe message. Must not contain paths, class names, or internal state. */
    protected string $publicMessage = 'The upload request could not be processed.';

    /** PSR-3 log level used when auditing this failure. */
    protected string $logLevel = 'warning';

    /** @var array<string, mixed> Structured, server-side-only audit context. */
    protected array $context = [];

    /**
     * @param  string  $internalMessage  Detailed, server-side reason (logged, never returned).
     * @param  array<string, mixed>  $context  Structured audit data (e.g. session_id, ip).
     */
    public function __construct(string $internalMessage = '', array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($internalMessage !== '' ? $internalMessage : $this->publicMessage, 0, $previous);
        $this->context = $context;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function publicMessage(): string
    {
        return $this->publicMessage;
    }

    public function logLevel(): string
    {
        return $this->logLevel;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Audit the failure server-side. Laravel's handler calls this automatically and,
     * because it does not return false, suppresses the handler's default logging so
     * the failure is recorded exactly once, on the package's configured channel.
     */
    public function report(): void
    {
        $channel = config('stateful-chunking.log_channel');
        $channelName = is_string($channel) ? $channel : null;

        Log::channel($channelName)->log($this->logLevel, $this->getMessage(), $this->context + [
            'exception' => static::class,
            'status' => $this->statusCode,
        ]);
    }

    public function toResponse($request): JsonResponse
    {
        /** @var Request $request */
        return new JsonResponse(['message' => $this->publicMessage], $this->statusCode);
    }
}
