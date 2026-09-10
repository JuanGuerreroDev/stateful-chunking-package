# Stateful Chunking Package for Laravel

[![Tests](https://github.com/JuanGuerreroDev/stateful-chunking-package/actions/workflows/tests.yml/badge.svg)](https://github.com/JuanGuerreroDev/stateful-chunking-package/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/juanoecr/stateful-chunking.svg?style=flat-square)](https://packagist.org/packages/juanoecr/stateful-chunking)
[![Total Downloads](https://img.shields.io/packagist/dt/juanoecr/stateful-chunking.svg?style=flat-square)](https://packagist.org/packages/juanoecr/stateful-chunking)
[![License](https://img.shields.io/packagist/l/juanoecr/stateful-chunking.svg?style=flat-square)](LICENSE)

High-performance, decoupled Stateful Chunking package for **Laravel 10, 11, 12, and 13** built with **Hexagonal Architecture** and **SOLID principles**. Powered by a **Universal Cache State Persistence** system (supporting all Laravel Cache stores: Redis, Memcached, Database, File, DynamoDB, Array) for session tracking, state TTL management, and atomic byte reassembly.

---

## Features

- **Decoupled Backend Package**: 100% pure Laravel backend package. Fits seamlessly into any existing API or microservice.
- **Universal Cache State Persistence**: Works out-of-the-box using any Laravel cache store (`redis`, `database`, `file`, `memcached`, `dynamodb`, `array`) with atomic locking support and automatic non-lock fallback.
- **Dual-Layer Integrity Validation**: Validates individual chunk checksums and full assembled file integrity against SHA-256 hashes.
- **Staged Upload Pattern & Cryptographic Tokens**: Returns AES-256 encrypted, HMAC-signed `upload_token`s upon completion. Protects against OWASP IDOR and Path Traversal with zero physical storage path exposure.
- **Hardened Upload Validation**: Rejects path-traversal, dot-file, trailing-dot/space, and double-extension file names, plus a configurable blocklist of ~45 executable extensions (`php`, `phtml`, `sh`, `exe`, …) with an optional strict allowlist. Bounds `total_chunks` to the declared `file_size` to neutralize storage-amplification DoS (CWE-770).
- **Consumer DX Helpers & Validation Rule**: First-class `StatefulChunking` facade (`resolveToken`) and `ValidUploadToken` validation rule for clean, decoupled integration in downstream business modules.
- **Configurable Storage**: Assembles files using Laravel's `Storage` facade (`local`, `s3`, `gcs`, etc.).
- **Event-Driven Lifecycle**: Dispatches native Laravel events (`ChunkSessionInitiated`, `ChunkUploaded`, `FileReassembled`, `ChunkSessionCancelled`) for easy extension with virus scanners, WebSockets, and metrics.
- **Garbage Collection (Stale Cleanup)**: expired sessions have their staging directory purged automatically the moment the expiry is detected, plus a schedulable Artisan sweep (`php artisan stateful-chunking:clear-stale`) that collects the abandoned uploads nobody ever comes back to read. See [Maintenance & Garbage Collection](#maintenance--garbage-collection).
- **Auto-Discovery & Zero Setup**: Auto-registers `StatefulChunkingServiceProvider` and REST API endpoints out-of-the-box.
- **Customizable Routes**: Custom prefix, route middlewares (`auth:sanctum`, `api`), and config overrides.

---

## Compatibility

| Package | PHP       | Laravel        |
| ------- | --------- | -------------- |
| `1.x`   | 8.2 – 8.4 | 10, 11, 12, 13 |

Every supported PHP × Laravel combination is exercised on CI — the full test suite, PHPStan (level 10), and Laravel Pint — and the response shape is pinned by a byte-for-byte contract snapshot, so the JSON a consumer receives is identical across framework versions. See the [Tests workflow](.github/workflows/tests.yml).

---

## Installation

Install the package via Composer:

```bash
composer require juanoecr/stateful-chunking
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=stateful-chunking-config
```

This will create `config/stateful-chunking.php` in your application.

---

## Configuration & Driver Setup

Customize package parameters in `config/stateful-chunking.php` or via `.env`:

```env
# Specific Cache Store: leave empty to use Laravel's default cache store, or specify store (redis, database, file, etc.)
STATEFUL_CHUNKING_CACHE_STORE=
# STATEFUL_CHUNKING_DRIVER is accepted as a legacy alias for CACHE_STORE (read as a fallback when CACHE_STORE is empty)

# Routes & Endpoint Configuration
STATEFUL_CHUNKING_ROUTES_ENABLED=true
STATEFUL_CHUNKING_ROUTE_PREFIX=api/chunks

# File & Session Limits
STATEFUL_CHUNKING_SIZE_BYTES=2097152
STATEFUL_CHUNKING_SESSION_TTL=21600
STATEFUL_CHUNKING_TOKEN_TTL=7200                    # staged upload_token lifetime (2h), independent of the session

# Upload Limits & Storage-Amplification Guardrails
STATEFUL_CHUNKING_MAX_FILE_SIZE_BYTES=10737418240   # 10 GB cap on the declared file size
STATEFUL_CHUNKING_MAX_TOTAL_CHUNKS=10000            # hard ceiling on the declared chunk count

# Storage Disk & Path
STATEFUL_CHUNKING_STORAGE_DISK=local
STATEFUL_CHUNKING_STORAGE_PATH=uploads

# Security & Disclosure
STATEFUL_CHUNKING_EXPOSE_SERVER_PATHS=false         # keep real filesystem paths out of API responses
STATEFUL_CHUNKING_LOG_CHANNEL=                      # dedicated log channel (empty = app default)

# Rate Limiting & Throttling (Requests per minute per user/IP)
STATEFUL_CHUNKING_RATE_LIMIT_ENABLED=true
STATEFUL_CHUNKING_RATE_INITIATE=10
STATEFUL_CHUNKING_RATE_UPLOAD=120
STATEFUL_CHUNKING_RATE_STATUS=60
STATEFUL_CHUNKING_RATE_COMPLETE=20
STATEFUL_CHUNKING_RATE_CANCEL=20
```

> **Filename allow/deny list:** the executable-extension blocklist that rejects `.php`, `.phtml`, `.sh`, `.exe`, … (and the optional strict `allowed_extensions` whitelist) live as arrays in the published `config/stateful-chunking.php`; they have no `.env` equivalent. Edit them there to tune which uploads are accepted.

---

## Maintenance & Garbage Collection

Chunks are staged on disk under `chunks_temp/<sessionId>/` and removed when the upload
reaches `/complete` or `/cancel`. Uploads that reach neither — the browser tab that was
closed halfway — leave their chunks behind. The package frees them in two ways.

### 1. Early purge on expiry (automatic)

When any request touches a session that has outlived its TTL, the package dispatches
`ChunkSessionExpired` and a built-in listener deletes that session's staging directory
in the same request. Nothing to configure.

This only fires if something reads the dead session, so it covers clients that come
back, and not the ones that never do. That is what the sweep is for.

### 2. Scheduled sweep (**required**, not optional)

```bash
php artisan stateful-chunking:clear-stale
```

Without `--session` this walks the staging area and collects every directory that
satisfies **both** conditions: its most recent chunk is older than `session_ttl`, **and**
the state store has no live session for it. Both are needed — age alone would delete the
chunks of a large file still uploading over a slow link.

The command reports what it actually did:

```
3 abandoned staging directories (1.412 GB) collected. 1 skipped as still live.
```

Use `--dry-run` to see the same report without deleting anything, and `--session=<id>`
to clear one session and its chunks by hand.

**Schedule it.** Without it, abandoned uploads are retained until the disk fills:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('stateful-chunking:clear-stale')->hourly();
```

> **Custom storage adapters**: the sweep needs to enumerate the staging area, which is
> declared by the separate `PrunableChunkStorageInterface`. The bundled
> `LocalStorageAdapter` implements it. If you bind your own `FileStorageInterface` and it
> does not, the command says so and collects nothing, rather than reporting a success it
> did not earn.

---

## API Endpoints Specification

When `STATEFUL_CHUNKING_ROUTES_ENABLED` is true, the package automatically exposes 5 REST endpoints protected by operation-specific rate limiters:

| Method | Endpoint | Description | Rate Limit (Default) |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/chunks/initiate` | Initiates a new chunk session or returns an active session by fingerprint | `10 req / min` |
| `POST` | `/api/chunks/upload` | Receives and stores an individual chunk payload | `120 req / min` |
| `GET` | `/api/chunks/status/{sessionId}` | Queries active chunk session status and pending chunk indices | `60 req / min` |
| `POST` | `/api/chunks/complete` | Triggers stream reassembly, integrity hash validation, and session cleanup | `20 req / min` |
| `DELETE` | `/api/chunks/cancel/{sessionId}` | Cancels an active session and purges state and temporary chunks | `20 req / min` |

---

## Authentication & Authorization

The package draws a deliberate line here, and it is worth understanding before you
deploy.

**Authentication is yours.** The package does not ship an auth flag and does not decide
who your users are — your application already knows, and its guard is the right one.
Declare it once and it gates all five endpoints at the framework level:

```php
// config/stateful-chunking.php
'routes' => [
    'middleware' => ['api', 'auth:sanctum'],
],
```

A package-owned flag was tried and removed: it lived in the two FormRequests that
happened to exist, so it covered `initiate` and `upload` and silently left `status`,
`complete` and `cancel` open. Half a policy is worse than none, because the operator
believes they have the whole one. The middleware entry above has no such gaps.

**Authorization is ours.** Only this package knows what a chunk session is and who owns
one, so it enforces that itself: every session records an owner, and `status`, `upload`,
`complete` and `cancel` return `403` to anyone else. The owner is derived from whatever
identity your guard established — `user:<id>` when authenticated, `ip:<address>` when
not.

Two consequences worth planning for:

- **Without a guard, ownership degrades to the source address.** Callers behind a shared
  NAT are one owner, and they can see and cancel each other's uploads. For anything
  multi-tenant, authenticate.
- **Ownership fails closed.** A session created programmatically through
  `StateRepositoryInterface` without an `ownerId` belongs to nobody and is unreachable
  over HTTP. If your application creates sessions outside the HTTP layer, set an owner.

### Custom caller identity

"A user or an address" is not everyone's model. If yours is a tenant, an API key or a
calling service, bind your own resolver — session ownership **and** rate-limit buckets
both follow it, because they read the same port:

```php
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\Contracts\ResolvesCallerIdentity;

$this->app->bind(ResolvesCallerIdentity::class, TenantCallerIdentity::class);
```

Your implementation returns a string that is **stable** for the same caller across
requests and **distinct** between callers who must not see each other's uploads.

It must be **scheme-qualified** as `<scheme>:<value>` — `tenant:7`, `api-key:abc123`,
`tenant:7:user:42`. The scheme is the part before the first colon and can be any
identifier you like; everything after it is opaque to the package. This is enforced by
the `SessionOwner` value object, and it is what stops a user whose id happens to be
`1.2.3.4` from sharing an owner and a rate-limit bucket with the caller arriving from
that address.

A resolver returning a bare identifier is a misconfiguration, not a client error, so it
surfaces as a `500` naming the binding to fix rather than silently producing a session
that belongs to nobody.

---

## Rate Limiting & DoS Protection

In accordance with **OWASP API Security (API4:2023 - Unrestricted Resource Consumption)**, this package registers dedicated, named rate limiters (`stateful-chunking-*`) in `StatefulChunkingServiceProvider` to protect against server resource starvation and abusive traffic:

- **Identity Resolution**: Limits are partitioned by the same identity the ownership check uses — `user:<id>` from `$request->user()->getAuthIdentifier()` when your guard authenticated the caller, falling back to `ip:<address>` for guests. Users sharing a corporate NAT or proxy therefore do not throttle each other, **provided your middleware authenticates them**: with no guard in front of the routes there is no user to key on, and every caller behind that address shares one bucket.
- **Differentiated Quotas**: While uploading chunks allows high throughput (`120 req/min`, up to 2 chunks/sec), session creation (`10 req/min`) and byte reassembly (`20 req/min`) are strictly capped to prevent disk inode exhaustion and CPU/worker starvation during stream operations.
- **HTTP 429 Handling**: If a client exceeds the threshold, Laravel returns a standard `HTTP 429 Too Many Requests` status with a `Retry-After` header.
- **Disabling for Tests**: Set `STATEFUL_CHUNKING_RATE_LIMIT_ENABLED=false` in your `.env.testing` or `phpunit.xml` to bypass throttling during integration tests.

For in-depth threat modeling and distributed cluster/multi-server cache configurations, consult the [Rate Limiting & DoS Prevention Guide](docs/security/rate_limiting_and_dos_prevention.md).

---

## Frontend Integration Guide (Client-side WebCrypto / JS)

The frontend client application is responsible for slicing the file into chunks, computing SHA-256 hashes, and invoking the REST endpoints.

### Frontend Hashing Requirement (WebCrypto)

Compute SHA-256 checksums in the browser using the native **WebCrypto API** (`window.crypto.subtle.digest`):

```typescript
// Helper function to compute SHA-256 in browser
export async function computeSha256(buffer: ArrayBuffer): Promise<string> {
  const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
}
```

### 1. Initiate Upload Session

```typescript
const response = await fetch('/api/chunks/initiate', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    file_name: file.name,
    file_size: file.size,
    total_chunks: totalChunks,
    total_hash: await computeSha256(await file.arrayBuffer()),
    fingerprint: `${file.name}_${file.size}_${file.lastModified}`,
  }),
});
const { data } = await response.json();
const sessionId = data.session_id;
```

### 2. Upload Individual Chunks

```typescript
const chunkBlob = file.slice(start, end);
const chunkBuffer = await chunkBlob.arrayBuffer();
const chunkHash = await computeSha256(chunkBuffer);

const formData = new FormData();
formData.append('session_id', sessionId);
formData.append('chunk_index', chunkIndex.toString());
formData.append('chunk_hash', chunkHash);
formData.append('file', chunkBlob);

await fetch('/api/chunks/upload', {
  method: 'POST',
  body: formData,
});
```

### 3. Reassemble File & Receive Staged Upload Token

```typescript
const response = await fetch('/api/chunks/complete', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ session_id: sessionId }),
});
const { data } = await response.json();
console.log('Upload token received:', data.upload_token);
// data.upload_token -> Crypted, HMAC-signed token for business form submission
```

---

## Backend Consumer Integration Guide (Staged Upload Pattern)

This package implements the **Staged Upload Pattern**. The chunking package acts as a secure staging landing area. The consumer application's business module (e.g., Multimedia, Invoices, User Documents) receives the `upload_token` from the frontend, validates it, and decides the permanent destination.

### 1. FormRequest Validation with `ValidUploadToken`

Validate incoming business requests using the built-in validation rule:

```php
namespace App\Modules\Multimedia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Juanoecr\StatefulChunking\Rules\ValidUploadToken;

class StoreMediaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title'        => 'required|string|max:255',
            'album_id'     => 'required|integer|exists:albums,id',
            'upload_token' => ['required', new ValidUploadToken()], // 🛡️ Rejects tampered/expired tokens with 422
        ];
    }
}
```

### 2. Resolving Staged Files & Moving to Permanent Storage

Use the `StatefulChunking` facade to safely decrypt the token and retrieve the `StagedFileDTO`:

```php
namespace App\Modules\Multimedia\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multimedia\Http\Requests\StoreMediaRequest;
use Juanoecr\StatefulChunking\Facades\StatefulChunking;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Media;

class MediaUploadController extends Controller
{
    public function store(StoreMediaRequest $request)
    {
        // 1. Resolve verified staged file from token
        $staged = StatefulChunking::resolveToken($request->input('upload_token'));

        // 2. Define your permanent business storage destination
        $permanentPath = sprintf('media/albums/%d/%s_%s', 
            $request->input('album_id'), 
            Str::uuid(), 
            $staged->fileName
        );

        // 3. Move from staging to permanent storage disk (e.g. S3)
        $stream = Storage::disk($staged->disk)->readStream($staged->tempPath);
        Storage::disk('s3')->writeStream($permanentPath, $stream);

        // 4. Delete temporary staged file
        Storage::disk($staged->disk)->delete($staged->tempPath);

        // 5. Persist record in your database
        $media = Media::create([
            'user_id'   => auth()->id(),
            'album_id'  => $request->input('album_id'),
            'title'     => $request->input('title'),
            'file_name' => $staged->fileName,
            'path'      => $permanentPath,
            'disk'      => 's3',
            'mime_type' => $staged->mimeType() ?? 'application/octet-stream',
            'size'      => $staged->fileSize,
            'sha256'    => $staged->hash,
        ]);

        return response()->json([
            'message' => 'Media stored successfully',
            'data'    => $media,
        ], 201);
    }
}
```

---

## Domain & Lifecycle Events

The package dispatches standard Laravel events throughout the chunking and reassembly lifecycle. You can attach listeners or subscribers in your application (e.g. for asynchronous virus scanning, real-time WebSocket progress, or metrics):

| Event | Full Namespace | Payload / Public Properties | Dispatched When |
| :--- | :--- | :--- | :--- |
| `ChunkSessionInitiated` | `Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events\ChunkSessionInitiated` | `$event->session` (`ChunkSession`) | A new upload session is created. |
| `ChunkUploaded` | `Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events\ChunkUploaded` | `$event->session`, `$event->chunkIndex`, `$event->chunkHash` | An individual chunk is verified and saved. |
| `FileReassembled` | `Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events\FileReassembled` | `$event->sessionId`, `$event->uploadToken`, `$event->filePath`, `$event->fileName`, `$event->fileSize`, `$event->hash`, `$event->reassemblyData` | File bytes are reassembled, hash verified, and token generated. |
| `ChunkSessionCancelled` | `Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events\ChunkSessionCancelled` | `$event->sessionId` (`string`) | Session is cancelled and temporary storage is purged. |

### Example: Asynchronous Post-Processing Listener

```php
namespace App\Listeners;

use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events\FileReassembled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class ScanFileForViruses implements ShouldQueue
{
    public function handle(FileReassembled $event): void
    {
        Log::info("Asynchronously analyzing reassembled file: {$event->filePath}", [
            'session_id'   => $event->sessionId,
            'file_name'    => $event->fileName,
            'file_size'    => $event->fileSize,
            'sha256'       => $event->hash,
            'upload_token' => $event->uploadToken,
        ]);

        // Non-destructive inspection (e.g., ClamAV scanner) while waiting for business form submission
    }
}
```

---

## Architecture

Contributing, or auditing how a request actually flows through the package? Start with
[`docs/architecture/`](docs/architecture/):

| Document | What it answers |
| :--- | :--- |
| [Layers and ports](docs/architecture/README.md) | How the layers are arranged, which way dependencies point, and where adapters are wired |
| [Request lifecycle](docs/architecture/request-lifecycle.md) | Each endpoint end to end, with the exact point where authorization happens |
| [Data transformations](docs/architecture/data-transformations.md) | Where every input is validated, normalised, and first trusted |

Design decisions and their rationale live in [`docs/decisions/`](docs/decisions/) as ADRs.

---

## Testing

Run isolated package tests via Pest and Orchestra Testbench:

```bash
vendor/bin/pest
```

Verify static analysis at PHPStan Level 10:

```bash
vendor/bin/phpstan analyse
```

---

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
