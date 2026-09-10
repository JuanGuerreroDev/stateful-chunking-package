<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Contracts\ResolvesCallerIdentity;

/**
 * The default identity: whoever the host application's guard authenticated, or the
 * request's source address when it authenticated nobody.
 *
 * The name says how it resolves, because that is the axis on which alternatives vary
 * — a `TenantCallerIdentity` or an `ApiKeyCallerIdentity` would answer the same
 * question from a different part of the request.
 *
 * The package does not authenticate; that is the consumer's guard, declared in
 * `stateful-chunking-upload.routes.middleware`. It does authorize, and for that it reads
 * whatever identity that guard established — through this one resolver, so that the
 * session owner and the rate-limit bucket can never disagree about who is calling.
 * They did once: the limiter had its own copy built on `property_exists($user, 'id')`,
 * which is always false for an Eloquent model because `id` lives in `$attributes`
 * behind `__get()`, so every authenticated caller was silently bucketed by address.
 *
 * Identities are namespaced so a user whose id happens to look like an address cannot
 * land in that address's bucket.
 */
final class RequestCallerIdentity implements ResolvesCallerIdentity
{
    public function resolve(Request $request): string
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
