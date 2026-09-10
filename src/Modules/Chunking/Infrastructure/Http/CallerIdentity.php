<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * The single place that answers "who is calling?".
 *
 * The package does not authenticate — that belongs to the host application, which
 * declares its guard in `stateful-chunking.routes.middleware`. But the package does
 * authorize: only it knows what a chunk session is and who owns one. To do that it
 * has to read whatever identity the consumer's guard established, and that reading
 * must happen in exactly one way.
 *
 * It previously happened in two. The controller derived the session owner with
 * `$user->getAuthIdentifier()`, while the service provider keyed rate limits with
 * `property_exists($user, 'id')` — which inspects *declared* properties, and Eloquent
 * keeps `id` in `$attributes` behind `__get()`. That branch was therefore always
 * false for any real User model, so every authenticated caller was silently throttled
 * by IP, and users behind a shared NAT consumed each other's quota. Two answers to
 * one question, one of them dead code.
 *
 * Identities are prefixed (`user:` / `ip:`) so a user whose id happens to look like an
 * address cannot collide with that address's bucket.
 *
 * Deliberately a plain final class rather than an injected port: there is no second
 * implementation in prospect, and both callers — a controller method and a closure
 * built during `boot()` — are infrastructure. An interface here would be indirection
 * bought with no seam to spend it on.
 */
final class CallerIdentity
{
    public static function resolve(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof Authenticatable) {
            $authId = $user->getAuthIdentifier();

            if ((is_string($authId) || is_int($authId)) && (string) $authId !== '') {
                return 'user:'.(string) $authId;
            }
        }

        return 'ip:'.($request->ip() ?: '127.0.0.1');
    }
}
