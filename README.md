# Stateful Chunking Package for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/stateful-chunking/laravel-package.svg?style=flat-square)](https://packagist.org/packages/stateful-chunking/laravel-package)
[![Total Downloads](https://img.shields.io/packagist/dt/stateful-chunking/laravel-package.svg?style=flat-square)](https://packagist.org/packages/stateful-chunking/laravel-package)
[![License](https://img.shields.io/packagist/l/stateful-chunking/laravel-package.svg?style=flat-square)](LICENSE)

High-performance, decoupled Stateful Chunking package for **Laravel 10, 11, and 12** built with **Hexagonal Architecture** and **SOLID principles**. Powered by a **Multi-Driver State Persistence** system (supporting Laravel Cache stores and Redis) for session tracking, state TTL management, and atomic byte reassembly.

---

## Features

- **Decoupled Backend Package**: 100% pure Laravel backend package. Fits seamlessly into any existing API or microservice.
- **Multi-Driver State Persistence**: Works out-of-the-box using Laravel 12's default cache system (`file`, `database`, `array`, `memcached`) or atomic `redis` driver.
- **Dual-Layer Integrity Validation**: Validates individual chunk checksums and full assembled file integrity against SHA-256 hashes.
- **Configurable Storage**: Assembles files using Laravel's `Storage` facade (`local`, `s3`, `gcs`, etc.).
- **Garbage Collection (Stale Cleanup)**: Built-in Artisan command (`php artisan stateful-chunking:clear-stale`) for purging expired upload sessions and orphaned temporary files.
- **Auto-Discovery & Zero Setup**: Auto-registers `StatefulChunkingServiceProvider` and REST API endpoints out-of-the-box.
- **Customizable Routes**: Custom prefix, route middlewares (`auth:sanctum`, `api`), and config overrides.

---

## Installation

Install the package via Composer:

```bash
composer require stateful-chunking/laravel-package
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

### 3. Reassemble File

```typescript
const response = await fetch('/api/chunks/complete', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ session_id: sessionId }),
});
const { data } = await response.json();
console.log('File successfully assembled at:', data.path);
```

---

## Testing

Run isolated package tests via Pest and Orchestra Testbench:

```bash
vendor/bin/pest
```

---

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
