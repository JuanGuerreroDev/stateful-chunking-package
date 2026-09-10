<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Tests\Feature\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunkingUpload\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stands in for the consumer's own guard (`auth:sanctum`, `auth:api`, ...) without
 * dragging a full auth stack into the package's test suite.
 */
final class RejectsGuestsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            abort(401);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}

/**
 * VULN-09 REGRESSION TEST: the consumer's middleware gates every endpoint (AF-002).
 *
 * The removed `require_auth` flag covered only `initiate` and `upload`, because it was
 * implemented in the two FormRequests that happened to exist. `status`, `complete` and
 * `cancel` take no FormRequest, so they stayed open while the README claimed the flag
 * gated "the chunk endpoints".
 *
 * The replacement is not a better flag — it is the extension point the package already
 * had. One entry in `stateful-chunking-upload.routes.middleware` gates all five endpoints at
 * the framework level, with the host application's own guard. This test pins that: a
 * single middleware, five endpoints, no exceptions.
 *
 * The middleware is applied through the environment rather than in the test body
 * because routes are registered during the provider's boot(), so a later Config::set
 * would arrive after the group was built.
 */
class Vuln09ConsumerAuthMiddlewareTest extends TestCase
{
    private const UNKNOWN_SESSION = '11111111-1111-4111-8111-111111111111';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('stateful-chunking-upload.routes.middleware', ['api', RejectsGuestsMiddleware::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (['initiate', 'upload', 'status', 'complete', 'cancel'] as $op) {
            Config::set("stateful-chunking-upload.rate_limits.{$op}", 1000);
            RateLimiter::clear("stateful-chunking-upload-{$op}");
        }
    }

    /**
     * All five endpoints — including the three the old flag never reached.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function endpointProvider(): array
    {
        return [
            'initiate' => ['POST', '/api/chunks/initiate', [
                'file_name' => 'x.bin',
                'file_size' => 10,
                'total_chunks' => 1,
                'total_hash' => '0000000000000000000000000000000000000000000000000000000000000000',
            ]],
            'upload' => ['POST', '/api/chunks/upload', [
                'session_id' => self::UNKNOWN_SESSION,
                'chunk_index' => 0,
                'chunk_hash' => '0000000000000000000000000000000000000000000000000000000000000000',
            ]],
            'status' => ['GET', '/api/chunks/status/'.self::UNKNOWN_SESSION, []],
            'complete' => ['POST', '/api/chunks/complete', ['session_id' => self::UNKNOWN_SESSION]],
            'cancel' => ['DELETE', '/api/chunks/cancel/'.self::UNKNOWN_SESSION, []],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('endpointProvider')]
    public function test_consumer_middleware_gates_every_endpoint(string $method, string $uri, array $payload): void
    {
        $response = $this->json($method, $uri, $payload);

        $this->assertSame(
            401,
            $response->status(),
            "{$method} {$uri} must be gated by the consumer's middleware, not reach the domain."
        );
    }
}
