<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\Contracts;

use Illuminate\Http\Request;

/**
 * Answers "who is calling?" for both things that need it: which caller owns a chunk
 * session, and which bucket a request is rate-limited in.
 *
 * This is an extension point, not an internal detail. The default implementation
 * knows two kinds of caller — an authenticated user and a bare source address — and
 * that is not enough for every deployment. A multi-tenant application needs the tenant
 * in the identity; an API gateway keys on the API key; a service mesh keys on the
 * calling service. Without a seam here, those consumers would have to fork the package
 * to change a single string, and no test could ever demonstrate the substitution
 * because the substitution would not be possible.
 *
 * Bind your own in a service provider:
 *
 *     $this->app->bind(ResolvesCallerIdentity::class, TenantCallerIdentity::class);
 *
 * Contract: the returned string identifies the caller and is compared with `!==`
 * against the owner recorded on a session, so it must be **stable** for the same
 * caller across requests, and **distinct** between callers who must not see each
 * other's uploads. Namespacing the value (the default uses `user:` / `ip:`) is what
 * stops one kind of identity from colliding with another.
 *
 * This port lives in Infrastructure rather than in `Core/Contracts` on purpose: it
 * takes an `Illuminate\Http\Request`, so placing it inside the core would make the
 * innermost layer depend on the framework and break the dependency rule that
 * `tests/Unit/Architecture/LayerDependencyRuleTest.php` enforces. It is an adapter
 * extension point, and calling it one keeps the layering honest.
 */
interface ResolvesCallerIdentity
{
    public function resolve(Request $request): string;
}
