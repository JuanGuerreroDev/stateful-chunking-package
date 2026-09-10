<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Contracts\ResolvesCallerIdentity;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Controllers\ChunkUploadController;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\RequestCallerIdentity;
use Juanoecr\StatefulChunkingUpload\Tests\TestCase;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Stands in for a consumer whose notion of "who is calling" is neither a user id nor
 * a source address — here, a tenant carried in a header.
 */
final class TenantCallerIdentity implements ResolvesCallerIdentity
{
    public function resolve(Request $request): string
    {
        return 'tenant:'.((string) $request->header('X-Tenant', '') ?: 'anonymous');
    }
}

/**
 * The seam that the first version of this refactor did not have.
 *
 * Caller identity started life as a static method on a final class. It removed the
 * duplicate implementation that AF-004 was made of, which was the point, but it also
 * fixed the package's answer to "who is calling?" at exactly two shapes: an
 * authenticated user, or a bare address. A multi-tenant deployment, an API gateway or
 * a service mesh needs a third, and could only get one by forking.
 *
 * Two SOLID principles were paying for that. OCP, because a new identity source meant
 * editing the resolver rather than adding one. DIP, because the controller and the
 * service provider both made a static call to a concrete class, so nothing could be
 * substituted — and note what that costs a test suite: the class reached 100% line
 * coverage while the behaviour that actually mattered, "a consumer can change this",
 * was untestable because it was impossible.
 *
 * These tests are that missing coverage. They rebind the port and assert the change
 * reaches **both** readers of the identity — session ownership and the rate-limit
 * bucket — because those two disagreeing is exactly what AF-004 was.
 */
class CallerIdentitySubstitutionTest extends TestCase
{
    private const CONTENT = 'TENANT CONTENT';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (['initiate', 'upload', 'status', 'complete', 'cancel'] as $op) {
            Config::set("stateful-chunking-upload.rate_limits.{$op}", 1000);
            RateLimiter::clear("stateful-chunking-upload-{$op}");
        }
    }

    private function useTenantIdentity(): void
    {
        $this->app->bind(ResolvesCallerIdentity::class, TenantCallerIdentity::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function initiatePayload(string $fingerprint): array
    {
        return [
            'file_name' => 'tenant.bin',
            'file_size' => strlen(self::CONTENT),
            'total_chunks' => 1,
            'total_hash' => hash('sha256', self::CONTENT),
            'fingerprint' => $fingerprint,
        ];
    }

    public function test_the_default_binding_is_the_request_resolver(): void
    {
        $this->assertInstanceOf(
            RequestCallerIdentity::class,
            $this->app->make(ResolvesCallerIdentity::class),
            'Consumers who bind nothing must keep the user/ip behaviour.'
        );
    }

    /**
     * Pins DIP structurally: if someone reverts the controller to calling a concrete
     * resolver, this fails even if every behavioural test still passes.
     */
    public function test_the_controller_depends_on_the_port_not_on_an_implementation(): void
    {
        $parameters = (new \ReflectionClass(ChunkUploadController::class))
            ->getConstructor()
            ?->getParameters() ?? [];

        $identityTypes = array_values(array_filter(array_map(
            static function (ReflectionParameter $parameter): ?string {
                $type = $parameter->getType();

                return $type instanceof ReflectionNamedType ? $type->getName() : null;
            },
            $parameters
        ), static fn (?string $type): bool => $type !== null && str_contains($type, 'CallerIdentity')));

        $this->assertSame(
            [ResolvesCallerIdentity::class],
            $identityTypes,
            'The controller must type-hint the port, never a concrete resolver.'
        );
    }

    /**
     * REGRESSION 1: substitution reaches session ownership. Two tenants sharing one
     * source address must not see each other's sessions — with the default resolver
     * they would be the same owner.
     */
    public function test_a_substituted_resolver_governs_session_ownership(): void
    {
        $this->useTenantIdentity();

        $sharedIp = ['REMOTE_ADDR' => '198.51.100.55'];

        $created = $this->withServerVariables($sharedIp)
            ->withHeaders(['X-Tenant' => 'acme'])
            ->postJson('/api/chunks/initiate', $this->initiatePayload('acme-fp'));
        $created->assertStatus(201);
        $sessionId = (string) $created->json('data.session_id');

        $otherTenant = $this->withServerVariables($sharedIp)
            ->withHeaders(['X-Tenant' => 'globex'])
            ->getJson("/api/chunks/status/{$sessionId}");
        $this->assertSame(403, $otherTenant->status(), 'A different tenant on the same address must be denied.');

        $sameTenant = $this->withServerVariables($sharedIp)
            ->withHeaders(['X-Tenant' => 'acme'])
            ->getJson("/api/chunks/status/{$sessionId}");
        $this->assertSame(200, $sameTenant->status(), 'The owning tenant must still be allowed.');
    }

    /**
     * REGRESSION 2: substitution reaches the rate-limit bucket as well. This is the
     * half that would silently not work if the provider captured a resolver at boot
     * instead of resolving one per request.
     */
    public function test_a_substituted_resolver_governs_rate_limit_buckets(): void
    {
        $this->useTenantIdentity();

        Config::set('stateful-chunking-upload.rate_limits.initiate', 1);
        RateLimiter::clear('stateful-chunking-upload.initiate');

        $sharedIp = ['REMOTE_ADDR' => '198.51.100.66'];

        $first = $this->withServerVariables($sharedIp)->withHeaders(['X-Tenant' => 'acme'])
            ->postJson('/api/chunks/initiate', $this->initiatePayload('acme-1'));
        $this->assertSame(201, $first->status());

        $sameTenantAgain = $this->withServerVariables($sharedIp)->withHeaders(['X-Tenant' => 'acme'])
            ->postJson('/api/chunks/initiate', $this->initiatePayload('acme-2'));
        $this->assertSame(429, $sameTenantAgain->status(), 'A tenant must exhaust their own quota.');

        $otherTenant = $this->withServerVariables($sharedIp)->withHeaders(['X-Tenant' => 'globex'])
            ->postJson('/api/chunks/initiate', $this->initiatePayload('globex-1'));
        $this->assertSame(
            201,
            $otherTenant->status(),
            'A second tenant on the same address must get its own bucket — the identity '.
            'used for throttling must be the substituted one, not a resolver captured at boot.'
        );
    }
}
