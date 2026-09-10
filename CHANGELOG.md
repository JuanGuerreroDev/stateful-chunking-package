# Changelog

All notable changes to `juanoecr/stateful-chunking` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-09-10

First published release. Resumable chunked uploads for Laravel, built as a staging area
rather than a storage layer: the package receives chunks, verifies them, assembles the
file outside the webroot, and hands the consuming application a signed token to claim it.
Where the file finally lives, and what business record it belongs to, stay the
consumer's decisions.

> **Operational requirement**: schedule `stateful-chunking:clear-stale`. Uploads that are
> abandoned before `/complete` or `/cancel` leave chunks staged, and the scheduled sweep is
> what reclaims them. See
> [Maintenance & Garbage Collection](README.md#maintenance--garbage-collection).

### Added

**The upload lifecycle**

- Five REST endpoints, registered automatically with a configurable prefix and middleware stack: `POST /initiate`, `POST /upload`, `GET /status/{sessionId}`, `POST /complete`, `DELETE /cancel/{sessionId}`.
- Resumable by design. A session tracks the status of every chunk index and reports the pending ones, so a client that reconnects uploads only what is missing. Passing a `fingerprint` resumes an existing session, but only when the caller owns it, its status still accepts chunks, and the declared `file_name`, `file_size`, `total_chunks` and `total_hash` all still match. A fingerprint that names a different file gets a new session rather than silently rebinding the previous one.
- Chunks may arrive as a multipart file, a string field, or the raw request body.
- **Staged upload tokens.** `/complete` returns an encrypted, authenticated token instead of a path. `StatefulChunking::resolveToken()` exchanges it for a `StagedFileDTO`, so the client never learns a filesystem location and the consumer chooses the permanent disk and path. The token's lifetime is deliberately shorter than the session's, so a leaked one expires first.
- Domain events for every lifecycle step: `ChunkSessionInitiated`, `ChunkUploaded`, `FileReassembled`, `ChunkSessionCancelled` and `ChunkSessionExpired`.

**Storage and state**

- State persists through Laravel's unified cache, so Redis, Memcached, database, file, DynamoDB and array stores all work without a package-specific driver.
- Mutations are serialised per session with the store's lock provider, falling back to an advisory file lock (`flock`) when the configured store offers none.
- Garbage collection on two paths. `ChunkSessionExpired` fires the moment an expiry is detected and a built-in listener frees that session's staging directory in the same request; the `stateful-chunking:clear-stale` sweep collects directories that are both older than `session_ttl` **and** unknown to the state store, reports the count and bytes reclaimed, and accepts `--dry-run`.

**Extension points**

- `StateRepositoryInterface` and `FileStorageInterface` are the outbound ports. Bind your own to change where state lives or where bytes go.
- `PrunableChunkStorageInterface` is **optional** and separate on purpose: sweeping the staging area is a maintenance capability, not part of the upload lifecycle, so an adapter that cannot enumerate directories is still a valid adapter. The bundled local adapter implements it.
- `ResolvesCallerIdentity` decides who is calling. Bind your own for a tenant, an API key or a calling service, and **both** session ownership and the rate-limit buckets follow it, because they read the same port. Identities are scheme-qualified (`user:42`, `ip:203.0.113.7`, `tenant:7:user:42`).

**Documentation and quality gates**

- CI matrix covering **PHP 8.2–8.4 × Laravel 10–13**, with Laravel Pint, PHPStan level 10 and a 90% coverage floor.
- JSON contract snapshots freeze the success envelope of every endpoint byte for byte, so the response shape cannot drift, and a new field cannot leak into it unnoticed.
- Architecture documentation under `docs/architecture/`: the layer graph and dependency rule, a request-lifecycle diagram per endpoint annotated with its authorization point, and a table recording where every input is validated, normalised and first trusted.
- Four Architecture Decision Records under `docs/decisions/`: adopting ADRs, the immutable response envelope, normalising identity at the adapter boundary, and the staged-chunk lifecycle.
- The rules that matter are executable rather than written down. An architecture test enforces the layer dependency rule in CI, and a documentation-consistency test checks the published claims against the code: the exception-to-status table against what the classes declare, the README rate limits against the config defaults, the ADR index against the ADR directory, and that every validated input appears in the trust table.

### Security

Stated as controls the package ships with, not as a history of repairs. The
pre-release hardening rounds live in the repository's commit history and in the ADRs.

- **Authorization on all five endpoints, fail closed.** Every lifecycle operation compares the session's owner against the caller's identity on the aggregate root itself, so no use case can bypass it. A session with no owner belongs to nobody rather than to everybody, which means host applications creating sessions programmatically through `StateRepositoryInterface` must set an owner or those sessions stay unreachable over HTTP.
- **Authentication is the host application's job, not the package's.** There is no auth flag to enable. Declare a guard once in `stateful-chunking.routes.middleware` and it covers all five endpoints; the package reads whatever identity that guard established and authorizes against it.
- **Identifiers are canonicalised once, at the adapter boundary, before any authorization decision.** Everything downstream receives the same canonical value, including the filesystem paths, which are derived from the resolved session rather than from the caller's string. Malformed identifiers are answered as missing sessions, never as server errors.
- **Dual-layer SHA-256 integrity**, per chunk on arrival and over the assembled file, compared in constant time. A mismatch at assembly unlinks the partial file before failing.
- **Hardened filename validation**: length cap, an anchored charset that admits no path separator, dot-file and trailing dot/space bans, an executable-extension blocklist applied to **every** segment after the stem rather than only the last, and an optional strict whitelist.
- **Enforced storage budgets.** The declared `file_size` is bounded two ways against `total_chunks`, so a one-byte declaration cannot stage gigabytes, and the cumulative byte budget is re-verified inside the same lock that increments the counter, so concurrent uploads cannot race past it.
- **Per-endpoint rate limits** partitioned by caller identity, with `user:` and `ip:` prefixes so a user id that looks like an address cannot collide with that address's bucket.
- **Nothing internal is echoed to clients.** The response envelope is an explicit allowlist: owner identifiers and server paths are omitted, unexpected exceptions render sanitised, and the staged token is stripped from audit context before logging.
- Every access-control failure raises a domain exception that renders the correct status and audits itself, so a denied request is always visible to the operator.

[Unreleased]: https://github.com/JuanGuerreroDev/stateful-chunking-package/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/JuanGuerreroDev/stateful-chunking-package/releases/tag/v1.0.0
