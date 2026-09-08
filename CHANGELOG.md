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
