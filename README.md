# Stateful Chunking Package for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/juanoecr/stateful-chunking.svg?style=flat-square)](https://packagist.org/packages/juanoecr/stateful-chunking)
[![Total Downloads](https://img.shields.io/packagist/dt/juanoecr/stateful-chunking.svg?style=flat-square)](https://packagist.org/packages/juanoecr/stateful-chunking)
[![License](https://img.shields.io/packagist/l/juanoecr/stateful-chunking.svg?style=flat-square)](LICENSE)

High-performance, decoupled Stateful Chunking package for **Laravel 10, 11, and 12** built with **Hexagonal Architecture** and **SOLID principles**. Powered by a **Multi-Driver State Persistence** system (supporting Laravel Cache stores and Redis) for session tracking, state TTL management, and atomic byte reassembly.

---

## Features

- **Decoupled Backend Package**: 100% pure Laravel backend package. Fits seamlessly into any existing API or microservice.
- **Multi-Driver State Persistence**: Works out-of-the-box using Laravel 12's default cache system (`file`, `database`, `array`, `memcached`) or atomic `redis` driver.
- **Dual-Layer Integrity Validation**: Validates individual chunk checksums and full assembled file integrity against SHA-256 hashes.
- **Staged Upload Pattern & Cryptographic Tokens**: Returns AES-256 encrypted, HMAC-signed `upload_token`s upon completion. Protects against OWASP IDOR and Path Traversal with zero physical storage path exposure.
- **Consumer DX Helpers & Validation Rule**: First-class `StatefulChunking` facade (`resolveToken`) and `ValidUploadToken` validation rule for clean, decoupled integration in downstream business modules.
- **Configurable Storage**: Assembles files using Laravel's `Storage` facade (`local`, `s3`, `gcs`, etc.).
- **Event-Driven Lifecycle**: Dispatches native Laravel events (`ChunkSessionInitiated`, `ChunkUploaded`, `FileReassembled`, `ChunkSessionCancelled`) for easy extension with virus scanners, WebSockets, and metrics.
- **Garbage Collection (Stale Cleanup)**: Built-in Artisan command (`php artisan stateful-chunking:clear-stale`) for purging expired upload sessions and orphaned temporary files.
- **Auto-Discovery & Zero Setup**: Auto-registers `StatefulChunkingServiceProvider` and REST API endpoints out-of-the-box.
- **Customizable Routes**: Custom prefix, route middlewares (`auth:sanctum`, `api`), and config overrides.

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
# Persistence Driver: 'cache' (default, uses Laravel Cache) or 'redis' (atomic Redis client)
STATEFUL_CHUNKING_DRIVER=cache

# Specific Cache Store (used when driver is 'cache'): default, file, database, redis, etc.
STATEFUL_CHUNKING_CACHE_STORE=default

# Routes & Endpoint Configuration
STATEFUL_CHUNKING_ROUTES_ENABLED=true
STATEFUL_CHUNKING_ROUTE_PREFIX=api/chunks

# File & Session Limits
STATEFUL_CHUNKING_SIZE_BYTES=2097152
STATEFUL_CHUNKING_SESSION_TTL=21600

# Storage Disk & Path
STATEFUL_CHUNKING_STORAGE_DISK=local
STATEFUL_CHUNKING_STORAGE_PATH=uploads
```

---

## Maintenance & Garbage Collection

To clean up expired upload sessions and orphaned temporary chunk files from storage, run the Artisan garbage collection command:

```bash
php artisan stateful-chunking:clear-stale
```

### Scheduling Automatic Cleanup

You can schedule this command in your application's `routes/console.php` (Laravel 11/12) or `app/Console/Kernel.php` (Laravel 10):

```php
use Illuminate\Support\Facades\Schedule;

// Run garbage collection every hour
Schedule::command('stateful-chunking:clear-stale')->hourly();
```

---

## API Endpoints Specification

When `STATEFUL_CHUNKING_ROUTES_ENABLED` is true, the package automatically exposes 5 REST endpoints:

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `POST` | `/api/chunks/initiate` | Initiates a new chunk session or returns an active session by fingerprint |
| `POST` | `/api/chunks/upload` | Receives and stores an individual chunk payload |
| `GET` | `/api/chunks/status/{sessionId}` | Queries active chunk session status and pending chunk indices |
| `POST` | `/api/chunks/complete` | Triggers stream reassembly, integrity hash validation, and session cleanup |
| `DELETE` | `/api/chunks/cancel/{sessionId}` | Cancels an active session and purges state and temporary chunks |

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

The package dispatches standard Laravel events throughout the chunking and reassembly lifecycle. You can attach listeners or subscribers in your application (e.g. for asynchronous virus scanning, WebSocket progress, or metrics):

| Event | Namespace | Dispatched When |
| :--- | :--- | :--- |
| `ChunkSessionInitiated` | `StatefulChunking\...\Events\ChunkSessionInitiated` | A new upload session is created. |
| `ChunkUploaded` | `StatefulChunking\...\Events\ChunkUploaded` | An individual chunk is verified and saved. |
| `FileReassembled` | `StatefulChunking\...\Events\FileReassembled` | File bytes are assembled, hash verified, and token generated. |
| `ChunkSessionCancelled` | `StatefulChunking\...\Events\ChunkSessionCancelled` | Session is cancelled and temporary storage is purged. |

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
