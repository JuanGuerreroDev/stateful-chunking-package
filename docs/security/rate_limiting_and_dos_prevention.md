# Rate Limiting & DoS Prevention Architecture

## 1. Overview & Security Context

Handling multipart chunked uploads introduces specific threat vectors associated with **resource exhaustion** and **denial of service (DoS)**. According to the **OWASP API Security Top 10 (API4:2023 - Unrestricted Resource Consumption)**, endpoints accepting state creation and file payloads must implement strict, differentiated throttling.

The `juanoecr/stateful-chunking` package enforces multi-tier rate limiting out-of-the-box using Laravel's native `RateLimiter` facade and `throttle` route middleware.

---

## 2. Threat Modeling for Chunked Uploads

| Threat Vector | Description | Package Mitigation |
| :--- | :--- | :--- |
| **Inode & Storage Exhaustion** | An attacker initiates millions of sessions without uploading chunks, creating abandoned state entries in the cache store. | Differentiated limit on `/api/chunks/initiate` (10 req/min) + Garbage Collection command (`stateful-chunking:clear-stale`). |
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

Limits are enforced per individual client using a dual-resolution strategy in `StatefulChunkingServiceProvider`:

```php
$resolveKey = function (Request $request): string {
    $user = $request->user();
    if (is_object($user) && property_exists($user, 'id') && (is_string($user->id) || is_int($user->id))) {
        return (string) $user->id;
    }
    return $request->ip() ?? '127.0.0.1';
};
```

- **Authenticated Clients (`auth:sanctum`, etc.)**: Rate limits are tied strictly to the authenticated user ID (`$user->id`). Users sharing a corporate NAT/proxy will not throttle each other.
- **Guest / Unauthenticated Clients**: Fallbacks gracefully to client IP (`$request->ip()`).

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
