---
status: 'accepted'
date: 2026-09-10
decision-makers: 'Juan Manuel Guerrero Cañón (maintainer)'
consulted: 'AUDIT-20260908-01 offensive security review, AF-003 (private, not published with the package)'
informed: 'Package consumers of the Chunking HTTP API and operators scheduling its maintenance command'
---

# Collect abandoned staging chunks by event and by sweep, not from the state repository

## Context and Problem Statement

An upload that reaches `/complete` or `/cancel` has its staging directory removed as part
of that operation. An upload that reaches neither does not, and that was the whole of the
package's disk hygiene.

Two facts compounded into an unbounded leak. `CacheStateRepository::deleteSession()`
clears the cache entry, the fingerprint mapping and the fallback lock, and never touches
`chunks_temp/<sessionId>/`. The lazy-expiry branch of `getSession()` calls exactly that
method, so the moment a session was found to be dead, its state vanished and its bytes
became unreachable — no longer described by anything, and therefore never deletable by
anything either.

Meanwhile `stateful-chunking-upload:clear-stale`, invoked without `--session`, did this:

```php
$this->info('Stateful Chunking garbage collection command executed successfully.');

return Command::SUCCESS;
```

The README instructed operators to schedule that hourly. So the package shipped a
maintenance task that reported success every hour while collecting nothing, and a test
that asserted the success string, certifying the no-op as correct behaviour.

This is worse than shipping no collector at all. A missing feature is visible; a feature
that reports success is a belief that the problem is handled. With the shipped defaults
the upload bucket admits roughly 276 MB per minute per caller, all of it retained
indefinitely by a client that simply never calls `/complete`.

The question this ADR settles is **which component is allowed to delete staged bytes,
and on whose signal** — because the obvious fix is the wrong one.

## Decision Drivers

- **The leak must close on both paths.** A client that returns and reads a dead session, and a client that never returns at all, are different failure modes and need different mechanisms.
- **The two ports must stay apart.** `StateRepositoryInterface` owns cache entries; `FileStorageInterface` owns bytes. Neither may reach into the other.
- **A custom storage adapter must not break.** Consumers already bind their own `FileStorageInterface` implementations. Cleanup must not become a method they are suddenly required to implement.
- **The collector must be auditable.** An operator has to be able to see what was reclaimed, and to see it *before* trusting it in production.
- **Never collect live work.** Deleting the chunks of an upload still in progress is a worse outcome than retaining an abandoned one.

## Considered Options

- **A — the state repository deletes the chunks itself** when it purges an expired session.
- **B — a domain event on expiry, plus a scheduled sweep**, with deletion performed by whoever owns the disk.
- **C — a scheduled sweep only**, driven purely by file age.

## Decision Outcome

Chosen option: **B**, because it is the only one that closes both paths without welding
the two ports together, and because the sweep alone cannot act soon enough while the
event alone cannot act at all for the clients that never come back.

### Consequences

- Good: expiry detected during a normal request frees the disk in that same request, so a returning client pays for its own cleanup immediately.
- Good: the sweep covers what the event structurally cannot — a session nobody ever reads again.
- Good: `PrunableChunkStorageInterface` is a separate, optional port, so existing custom adapters keep working untouched.
- Good: the sweep reports real counts and reclaimed bytes and offers `--dry-run`, so an operator can inspect before trusting.
- Bad: `getSession()` is now explicitly a read with a side effect, and the interface has to say so. It always was one; the change is that it is now documented and depended upon.
- Bad: scheduling moves from a suggestion to a requirement. Documented in bold in the README, because a consumer who skips it still leaks.
- Neutral: the listener is synchronous. Queueing it would make garbage collection depend on the consumer running queue workers, and a package that silently stops collecting when workers are down is back where it started.

### Why not A

Having `CacheStateRepository` call `FileStorageInterface::deleteTemporaryChunks()` is
three lines and looks like the obvious fix. It couples the two ports the hexagon exists
to keep apart: every future state repository — a Redis-native one, a database one, a
consumer's own — would inherit responsibility for deleting files it knows nothing about,
and would be wrong the moment the storage adapter is not the local one. Option B keeps
the announcement in the domain and the deletion in the layer that owns bytes.

### Why not C

A sweep alone cannot run often enough to matter. Between hourly runs the staging area
holds every abandoned upload, and the operator who lengthens the interval to reduce I/O
silently lengthens the retention window for data that has already expired. The event
makes expiry-triggered cleanup immediate and free.

## Implementation Plan

- **Affected paths**: `src/Core/Contracts/`, `src/Console/Commands/ClearStaleSessionsCommand.php`, `src/Modules/Chunking/Domain/Events/`, `src/Modules/Chunking/Infrastructure/{Listeners,Repositories,Storage}/`, `src/Providers/StatefulChunkingServiceProvider.php`.
- **`PrunableChunkStorageInterface`** in `src/Core/Contracts/`: `staleChunkDirectories(int $olderThanSeconds): array` and `chunkDirectorySizeInBytes(string $sessionId): int`. Pure PHP, no framework types, so the layer rule holds.
- **`LocalStorageAdapter`** implements it over `directories('chunks_temp')`, dating each directory by its **most recent** write. Dating by the oldest chunk would collect a slow upload in progress.
- **`ChunkSessionExpired`** domain event carrying `sessionId` and `totalChunks` — the second because the session is already gone by the time a listener runs.
- **`CacheStateRepository::getSession()`** dispatches it in the lazy-expiry branch, before `deleteSession()`.
- **`PurgeExpiredSessionChunks`** listener in `Infrastructure/Listeners/`, registered with `Event::listen` in the composition root. Idempotent: deleting an absent directory is a no-op, so concurrent detections cannot conflict.
- **`ClearStaleSessionsCommand`** gains a real sweep, `--dry-run`, and a warning when the bound adapter is not prunable.
- **Pattern to follow**: the collection criterion is a conjunction — stale mtime **and** no live session — and must stay one.

## Verification

- [x] The audit's proof-of-concept is inverted: after the expiry is detected, the `.tmp` file is gone from disk.
- [x] The sweep collects a directory whose session state is absent, and reports a real count and byte total.
- [x] The sweep skips a directory older than the TTL whose session is still live.
- [x] `--dry-run` produces the same report and deletes nothing.
- [x] A `FileStorageInterface` implementation that is not prunable produces a warning and no claim of success.
- [x] Purging twice is harmless.
- [x] **Negative control, sweep**: restoring the old no-op body fails 8 of the 12 tests.
- [x] **Negative control, listener**: removing the `Event::listen` registration fails the purge test while the dispatch test still passes, proving the two are not redundant.

## More Information

Supersedes nothing. Related: [ADR-0003](0003-normalise-identity-at-the-adapter-boundary.md)
shares the finding that this package's defects live between layers rather than inside
controls — there, an identifier trusted before it was normalised; here, a lifecycle whose
state half and disk half were each correct on their own and connected to nothing.

The trade-off recorded under `LIVE-004` is untouched: staged files are still validated by
declared extension only, and remain the consumer's to inspect before moving.
