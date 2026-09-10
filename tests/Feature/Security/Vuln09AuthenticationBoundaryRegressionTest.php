<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\RequestCallerIdentity;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * VULN-09 REGRESSION TEST: the authentication / authorization boundary (AF-002, AF-004).
 *
 * The package used to ship its own `require_auth` flag, which gated `initiate` and
 * `upload` through FormRequest::authorize() and left `status`, `complete` and `cancel`
 * open — while the README advertised it as covering "the chunk endpoints". Half a
 * policy is worse than none, because the operator believes they have the whole one.
 *
 * The boundary is now explicit and enforced by these tests:
 *
 *  - The package does NOT authenticate. That is the host application's job, declared
 *    in `stateful-chunking.routes.middleware`, where one entry gates all five
 *    endpoints with the app's own guard.
 *  - The package DOES authorize: only it knows what a session is and who owns one.
 *    For that it reads whatever identity the consumer's guard established — through
 *    exactly one resolver, {@see RequestCallerIdentity}.
 *
 * The second half is where AF-004 lived: the rate limiter resolved identity with
 * `property_exists($user, 'id')`, which is always false for an Eloquent model because
 * `id` lives in `$attributes` behind `__get()`. Every authenticated caller was
 * therefore throttled by IP, so users behind one NAT consumed each other's quota —
 * the exact opposite of what the README promised.
 */
class Vuln09AuthenticationBoundaryRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (['initiate', 'upload', 'status', 'complete', 'cancel'] as $op) {
            Config::set("stateful-chunking.rate_limits.{$op}", 1000);
            RateLimiter::clear("stateful-chunking-{$op}");
        }
    }

    private function makeUser(int|string $id): Authenticatable
    {
        $user = new class extends Model implements Authenticatable
        {
            use AuthenticatableTrait;

            protected $guarded = [];
        };

        $user->forceFill(['id' => $id]);

        return $user;
    }

    /**
     * REGRESSION 1: on its own the package makes no authentication claim. A guest
     * reaches the domain on every endpoint — answers are 404/200/201, never an
     * authorization refusal invented by the package.
     */
    public function test_the_package_alone_does_not_authenticate_any_endpoint(): void
    {
        $unknown = '11111111-1111-4111-8111-111111111111';
        $content = 'GUEST CONTENT';

        $initiate = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'guest.bin',
            'file_size' => strlen($content),
            'total_chunks' => 1,
            'total_hash' => hash('sha256', $content),
        ]);
        $this->assertSame(201, $initiate->status(), 'initiate must reach the domain');

        $status = $this->getJson("/api/chunks/status/{$unknown}");
        $this->assertSame(404, $status->status(), 'status must reach the domain');

        $complete = $this->postJson('/api/chunks/complete', ['session_id' => $unknown]);
        $this->assertSame(404, $complete->status(), 'complete must reach the domain');

        $cancel = $this->deleteJson("/api/chunks/cancel/{$unknown}");
        $this->assertSame(200, $cancel->status(), 'cancel must reach the domain');
    }

    /**
     * REGRESSION 2: the identity resolver reads what the guard established. This is
     * the assertion the old implementation could never have passed.
     */
    public function test_caller_identity_reads_the_authenticated_user(): void
    {
        $user = $this->makeUser(42);

        $request = Request::create('/api/chunks/initiate', 'POST');
        $request->setUserResolver(fn (): Authenticatable => $user);

        $this->assertSame('user:42', (new RequestCallerIdentity)->resolve($request));

        // Why the previous implementation was dead code: Eloquent keeps `id` in
        // $attributes behind __get(), so it is not a declared property.
        $this->assertFalse(property_exists($user, 'id'));
    }

    public function test_caller_identity_falls_back_to_ip_for_guests(): void
    {
        $request = Request::create('/api/chunks/initiate', 'POST', server: ['REMOTE_ADDR' => '198.51.100.10']);

        $this->assertSame('ip:198.51.100.10', (new RequestCallerIdentity)->resolve($request));
    }

    /**
     * REGRESSION 3: identities are prefixed, so a user whose id happens to look like
     * an address cannot land in that address's bucket.
     */
    public function test_user_and_ip_identities_cannot_collide(): void
    {
        $user = $this->makeUser('198.51.100.10');

        $authenticated = Request::create('/api/chunks/initiate', 'POST', server: ['REMOTE_ADDR' => '198.51.100.10']);
        $authenticated->setUserResolver(fn (): Authenticatable => $user);

        $guest = Request::create('/api/chunks/initiate', 'POST', server: ['REMOTE_ADDR' => '198.51.100.10']);

        $this->assertNotSame((new RequestCallerIdentity)->resolve($authenticated), (new RequestCallerIdentity)->resolve($guest));
    }

    /**
     * REGRESSION 4: the behaviour AF-004 actually cost users. Two authenticated
     * callers sharing one source address must not share a rate-limit bucket.
     */
    public function test_rate_limits_partition_per_user_not_per_shared_address(): void
    {
        Config::set('stateful-chunking.rate_limits.initiate', 1);
        RateLimiter::clear('stateful-chunking-initiate');

        $sharedIp = ['REMOTE_ADDR' => '198.51.100.77'];
        $content = 'NAT CONTENT';
        $payload = [
            'file_name' => 'nat.bin',
            'file_size' => strlen($content),
            'total_chunks' => 1,
            'total_hash' => hash('sha256', $content),
        ];

        $first = $this->actingAs($this->makeUser(1))
            ->withServerVariables($sharedIp)
            ->postJson('/api/chunks/initiate', $payload + ['fingerprint' => 'u1']);
        $this->assertSame(201, $first->status());

        $sameUserAgain = $this->actingAs($this->makeUser(1))
            ->withServerVariables($sharedIp)
            ->postJson('/api/chunks/initiate', $payload + ['fingerprint' => 'u1-again']);
        $this->assertSame(429, $sameUserAgain->status(), 'the same user must exhaust their own quota');

        $otherUser = $this->actingAs($this->makeUser(2))
            ->withServerVariables($sharedIp)
            ->postJson('/api/chunks/initiate', $payload + ['fingerprint' => 'u2']);
        $this->assertSame(
            201,
            $otherUser->status(),
            'a second authenticated user behind the same address must have their own quota'
        );
    }
}
