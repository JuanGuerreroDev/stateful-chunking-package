# Changelog

All notable changes to `juanoecr/stateful-chunking` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- CI matrix verifying **PHP 8.2–8.4 × Laravel 10–13**, with Laravel Pint and PHPStan (level 10) gates and a 90% test-coverage floor.
- JSON response-contract snapshots freezing the success envelope of every endpoint, so the response shape cannot drift (or leak a new field) unnoticed.
- Architecture Decision Records under `docs/decisions/` (ADR-0001 adopting ADRs, ADR-0002 the immutable response envelope).
- README compatibility matrix and a Tests status badge.

### Security

- **Fixed an authorization bypass on `POST /upload`.** The ownership guard resolved the session with the raw request value while `SessionId` lowercased it afterwards, so an upper-case UUID missed the guarded cache lookup, the guard read the resulting `null` as "no session to protect", and the Action then found the victim's session anyway — accepting a foreign chunk **and emitting no audit entry**. Identifiers are now canonicalised once, at the adapter boundary, before any authorization decision (ADR-0003).
- **Ownership now fails closed.** A session whose `ownerId` is `null` was treated as belonging to everybody by both the guard and fingerprint reuse; it now belongs to nobody. Host applications that create sessions programmatically through `StateRepositoryInterface` **must set an owner**, or those sessions become inaccessible over HTTP.
- Actions derive filesystem paths from the resolved session's own identifier rather than from the caller-supplied string.

### Added

- `CompleteChunkRequest` validates `session_id` on `POST /complete` with the same UUID regex the other endpoints use; `/status` and `/cancel` gained route pattern constraints, so a malformed identifier is answered with 404 instead of reaching the domain.
- Architecture documentation under `docs/architecture/`: the layer graph and dependency rule, a request lifecycle diagram per endpoint annotated with its authorization point, and a table recording where every input is validated, normalised and first trusted.
- `tests/Unit/Architecture/LayerDependencyRuleTest.php` enforces the dependency rule in CI, so it cannot drift back into prose.

### Fixed

- `FileReassembled` event is now dispatched with positional arguments, restoring compatibility with Laravel 10 and 11 (their `Dispatchable::dispatch()` drops named arguments, which broke every `/complete` call).

<!--
  Note: entries above cover recent work only. Older changes made after the
  1.0.0 tag were not tracked in a changelog; backfill them here as needed.
-->

## [1.0.0]

- Initial tagged release: stateful chunked uploads with multi-driver cache state persistence, staged AES-256 encrypted `upload_token`s, SHA-256 dual-layer integrity verification, and hardened upload validation.

[Unreleased]: https://github.com/JuanGuerreroDev/stateful-chunking-package/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/JuanGuerreroDev/stateful-chunking-package/releases/tag/v1.0.0
