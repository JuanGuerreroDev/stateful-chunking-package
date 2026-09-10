# Rate Limiting & DoS Prevention Architecture

## 1. Overview & Security Context

Handling multipart chunked uploads introduces specific threat vectors associated with **resource exhaustion** and **denial of service (DoS)**. According to the **OWASP API Security Top 10 (API4:2023 - Unrestricted Resource Consumption)**, endpoints accepting state creation and file payloads must implement strict, differentiated throttling.

The `juanoecr/stateful-chunking` package enforces multi-tier rate limiting out-of-the-box using Laravel's native `RateLimiter` facade and `throttle` route middleware.

---

## 2. Threat Modeling for Chunked Uploads

| Threat Vector | Description | Package Mitigation |
| :--- | :--- | :--- |
| **Inode & Storage Exhaustion** | An attacker initiates many sessions and uploads chunks it never completes, leaving staging directories behind. The initiate limit caps how many sessions are opened; the **upload** limit is what caps bytes on disk, at roughly 240 MB/min per caller with the shipped defaults. | Differentiated limit on `/api/chunks/initiate` (10 req/min), plus two collectors: the `ChunkSessionExpired` listener frees a session's directory the moment its expiry is detected, and the `stateful-chunking:clear-stale` sweep collects directories that are both older than `session_ttl` and unknown to the state store. **Scheduling the sweep is required**, not optional — see [ADR-0004](../decisions/0004-staged-chunk-lifecycle-and-garbage-collection.md). |
| **I/O & Worker Starvation** | An attacker floods the upload endpoint with micro-requests to saturate disk write buffers and PHP-FPM worker threads. | Differentiated limit on `/api/chunks/upload` (120 req/min) allowing fast legitimate sequential uploads while capping abuse. |
| **CPU & Memory Spike on Assembly** | Reassembling large files (e.g. 10 GB) consumes CPU for stream copying and full-file SHA-256 validation. Mass concurrent calls could crash the server. | Differentiated limit on `/api/chunks/complete` (20 req/min) backed by atomic distributed cache locking (`lockProvider`). |
| **Tampered State & Lock Contention** | Repeated polling or status checking to exhaust cache bandwidth. | Differentiated limit on `/api/chunks/status` (60 req/min). |

---

## 3. Differentiated Rate Limiters Specification

The package registers named limiters in `StatefulChunkingServiceProvider` under the `stateful-chunking-*` namespace:

| Limiter Name | Protected Endpoint | HTTP Method | Default Limit | Rationale |
| :--- | :--- | :--- | :--- | :--- |
| `stateful-chunking-initiate` | `/api/chunks/initiate` | `POST` | **10 req / min** | Prevents session spamming and orphan session generation. |
| `stateful-chunking-upload` | `/api/chunks/upload` | `POST` | **120 req / min** | Allows up to 2 chunks/sec per client (supports fast uploads and small chunks). |
| `stateful-chunking-status` | `/api/chunks/status/{sessionId}` | `GET` | **60 req / min** | Allows client polling up to once per second during recovery. |
| `stateful-chunking-complete` | `/api/chunks/complete` | `POST` | **20 req / min** | Protects CPU and disk streams during final byte assembly and hashing. |
| `stateful-chunking-cancel` | `/api/chunks/cancel/{sessionId}` | `DELETE` | **20 req / min** | Throttles session aborts and associated storage purge operations. |

---

## 4. Client Identity Resolution

Limits are keyed by the **same identity the ownership check uses**, resolved once through
the `ResolvesCallerIdentity` port and shared by both readers:

```php
$resolveKey = static fn (Request $request): string => app(ResolvesCallerIdentity::class)->resolve($request);
```

The default implementation, `RequestCallerIdentity`, returns `user:<id>` from the
authenticated user's `getAuthIdentifier()`, and falls back to `ip:<address>` when the
host application's guard authenticated nobody.

Three things are worth stating precisely, because an earlier version of this document
got all three wrong.

**Partitioning per user depends on your middleware.** The package does not authenticate.
If no guard runs in front of the routes there is no user to key on, and every caller
behind one address shares a bucket. Declare your guard in
`stateful-chunking.routes.middleware`.

**The prefixes are load-bearing.** Keys are namespaced `user:` and `ip:` so a user whose
id happens to look like an address cannot land in that address's bucket. This is enforced
by the `SessionOwner` value object, which requires every identity to be
scheme-qualified.

**Do not read the identifier off the model's properties.** This document previously
published an implementation built on `property_exists($user, 'id')`. That expression is
**always false** for an Eloquent model, because `id` lives in `$attributes` behind
`__get()` and is not a declared property. Every authenticated caller was therefore
bucketed by address, the precise opposite of what this section claimed, and users sharing
a corporate NAT consumed each other's quota. The audit finding was `AF-004`. Use
`getAuthIdentifier()`.

### Substituting the identity

If your notion of a caller is a tenant, an API key or a calling service, bind your own
resolver. Session ownership **and** the rate-limit buckets follow it, because they read
the same port:

```php
$this->app->bind(ResolvesCallerIdentity::class, TenantCallerIdentity::class);
```

Return a value that is stable for the same caller across requests, distinct between
callers who must not see each other's uploads, and scheme-qualified as
`<scheme>:<value>` — `tenant:7`, `api-key:abc123`, `tenant:7:user:42` are all valid.

---

## 5. Configuration & Overrides

All thresholds can be tuned in `config/stateful-chunking.php` or via environment variables in `.env`:

```env
# Enable or disable throttling across all package routes
STATEFUL_CHUNKING_RATE_LIMIT_ENABLED=true

# Custom per-minute limits per operation
STATEFUL_CHUNKING_RATE_INITIATE=10
STATEFUL_CHUNKING_RATE_UPLOAD=120
STATEFUL_CHUNKING_RATE_STATUS=60
STATEFUL_CHUNKING_RATE_COMPLETE=20
STATEFUL_CHUNKING_RATE_CANCEL=20
```

### Disabling Throttling in Tests / CI
In integration testing or local benchmarking suites, disable rate limits in `phpunit.xml` or `.env.testing`:

```xml
<env name="STATEFUL_CHUNKING_RATE_LIMIT_ENABLED" value="false"/>
```

---

## 6. Multi-Server & Cluster Deployments

In multi-node architectures (behind load balancers like AWS ALB, Cloudflare, or NGINX), Laravel's `RateLimiter` must use a centralized, shared cache store (such as Redis, Memcached, DynamoDB, or Database). 

Ensure your host application's default cache store or chunking cache store uses a centralized store:
```env
STATEFUL_CHUNKING_CACHE_STORE=redis
# or default store:
CACHE_STORE=redis
```
When configured with a centralized cache store, rate limiter buckets are shared atomically across all web instances, preventing attackers from multiplying request quotas across multiple servers.

---

## 7. Client Handling (HTTP 429 Too Many Requests)

When a rate limit is exceeded, Laravel returns an **HTTP 429 Too Many Requests** response with a `Retry-After` header indicating the number of seconds until requests are permitted again:

```http
HTTP/1.1 429 Too Many Requests
Content-Type: application/json
Retry-After: 42

{
    "message": "Too Many Requests"
}
```

Frontend clients should implement exponential backoff with jitter when encountering an HTTP 429 status code.
