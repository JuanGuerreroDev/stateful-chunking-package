# Stateful Chunking Package for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/stateful-chunking/laravel-package.svg?style=flat-square)](https://packagist.org/packages/stateful-chunking/laravel-package)
[![Total Downloads](https://img.shields.io/packagist/dt/stateful-chunking/laravel-package.svg?style=flat-square)](https://packagist.org/packages/stateful-chunking/laravel-package)
[![License](https://img.shields.io/packagist/l/stateful-chunking/laravel-package.svg?style=flat-square)](LICENSE)

High-performance, decoupled Stateful Chunking package for **Laravel 10, 11, and 12** built with **Hexagonal Architecture** and **SOLID principles**. Powered by **Redis** for state tracking, session TTL management, and atomic byte reassembly.

---

## 🚀 Features

- **Decoupled Backend Package**: 100% pure Laravel backend package. Fits into any existing API or microservice.
- **Redis Session Management**: Tracks chunk completion maps, upload progress, and TTLs atomically.
- **Dual-Layer Integrity**: Validates chunk checksums and full assembled file checksums against SHA-256 hashes.
- **Configurable Storage**: Assembles files using Laravel's `Storage` facade (`local`, `s3`, `gcs`, etc.).
- **Auto-Discovery & Zero Setup**: Auto-registers `StatefulChunkingServiceProvider` and REST API endpoints out-of-the-box.
- **Customizable Routes**: Custom prefix, route middlewares (`auth:sanctum`, `api`), and config overrides.

---

## 📦 Installation

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

## ⚙️ Configuration

Customize the package parameters in `config/stateful-chunking.php` or via `.env`:

```env
STATEFUL_CHUNKING_ROUTES_ENABLED=true
STATEFUL_CHUNKING_ROUTE_PREFIX=api/chunks
STATEFUL_CHUNKING_SIZE_BYTES=2097152
STATEFUL_CHUNKING_REDIS_TTL=21600
STATEFUL_CHUNKING_REDIS_CONNECTION=default
STATEFUL_CHUNKING_STORAGE_DISK=local
STATEFUL_CHUNKING_STORAGE_PATH=uploads
```

---

## 🔌 API Endpoints Specification

When `STATEFUL_CHUNKING_ROUTES_ENABLED` is true, the package automatically exposes 5 REST endpoints:

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `POST` | `/api/chunks/initiate` | Initiates a new chunk session or returns an active session by fingerprint |
| `POST` | `/api/chunks/upload` | Receives and stores an individual chunk payload |
| `GET` | `/api/chunks/status/{sessionId}` | Queries active chunk session status and pending chunk indices |
| `POST` | `/api/chunks/complete` | Triggers stream reassembly, integrity hash validation, and session cleanup |
| `DELETE` | `/api/chunks/cancel/{sessionId}` | Cancels an active session and purges Redis state and temporary chunks |

---

## 💡 Frontend Integration Guide (Client-side WebCrypto / JS)

The frontend client application is responsible for slicing the file into chunks, computing SHA-256 hashes, and invoking the REST endpoints.

### Frontend Hashing Requirement (WebCrypto)

It is recommended to compute SHA-256 checksums in the browser using the native **WebCrypto API** (`window.crypto.subtle.digest`):

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

## 🧪 Testing

Run isolated package tests via Pest and Orchestra Testbench inside the package directory:

```bash
vendor/bin/pest
```

---

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
