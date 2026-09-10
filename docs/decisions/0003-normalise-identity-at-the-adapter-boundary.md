---
status: 'accepted'
date: 2026-09-09
decision-makers: 'Juan Manuel Guerrero Cañón (maintainer)'
consulted: 'AUDIT-20260908-01 offensive security review, AF-001, AF-006, AF-010 (private, not published with the package)'
informed: 'Package consumers of the Chunking HTTP API'
---

# Normalise caller and session identity at the adapter boundary

## Context and Problem Statement

The package authorizes every lifecycle operation by comparing the session's `ownerId`
against the caller's identity. The control itself was correct and covered by tests. It
was bypassable anyway.

`SessionId` accepts a UUID case-insensitively and stores it lowercased.
`UploadChunkRequest` validates with the same case-insensitive regex, so an upper-case
identifier is a valid request. The controller then resolved the session with the **raw**
request value to run the ownership check, and only afterwards built the DTO, where
`SessionId` normalised it. Cache keys are case-sensitive, so the guarded lookup missed,
returned `null`, and `assertSessionOwnership()` took its early-return — the branch that
means "there is no session to protect". The Action that followed looked the session up
again, this time with the normalised id, and found the victim's session.

Uppercasing one hex digit therefore turned a 403 into a 200. Worse, because the guard
returned early rather than throwing, no `UnauthorizedSessionAccessException` was raised
and **no audit entry was written**: the intrusion was invisible to the operator.

Two related defects share the shape. `assertSessionOwnership()` treated a session with a
`null` owner as belonging to everybody rather than to nobody, so authorization failed
open; and `ReassembleFileAction` / `CancelChunkSessionAction` built filesystem paths from
the caller-supplied string rather than from the session they had just resolved.

The common cause is not a missing control. It is that **the same value had two different
canonical forms in two different layers, and the authorization decision was made against
the wrong one**.

## Decision Drivers

* An access-control bypass must not be reachable by reformatting an input.
* Access control must deny by default (OWASP A01 prevention control 1).
* The mechanism must be implemented once and reused, not restated per endpoint
  (OWASP A01 prevention control 2).
* Every access-control failure must be logged (OWASP A01 prevention control 6).
* RFC 4122 makes UUIDs case-insensitive on input; legitimate clients that send upper
  case must keep working.
* The rule must survive contributors who have not read this document.

## Considered Options

* **Option A** — Make `SessionId` reject non-canonical input (drop the `/i` flag).
* **Option B** — Normalise once at the adapter boundary, before any authorization
  decision, and pass the canonical value downstream. *(chosen)*
* **Option C** — Change the Actions to accept a `SessionId` value object instead of a
  string, so the type system enforces canonicality.

## Decision Outcome

**Option B.** The HTTP adapter canonicalises the session identifier exactly once, in
`ChunkUploadController::canonicalSessionId()`, before the ownership check, and hands that
same value to the repository lookup, to the Action, and to everything derived from it.

Supporting changes that close the same class of defect:

* Ownership **fails closed**: `$session->ownerId !== $attemptedBy` denies when the owner
  is `null`. Fingerprint reuse applies the same test.
* `/complete` gets a `CompleteChunkRequest` enforcing the UUID shape that every other
  endpoint already required, and `/status` and `/cancel` get route pattern constraints,
  so a malformed identifier is a routing miss rather than an unvalidated value arriving
  at the guard.
* Actions derive side effects from `$session->sessionId->value` — the entity they
  resolved — never from the string they were called with.

### Consequences

* Good: the bypass is closed on all four endpoints that accept a session id, and the
  audit entry is emitted again, so the attempt is visible.
* Good: `/complete` and the route parameters are validated consistently with `/upload`;
  a malformed id now yields 404 instead of reaching the domain.
* Good: RFC 4122 tolerance is preserved — the owner sending upper case still succeeds.
* Bad: a session persisted through `StateRepositoryInterface` without an `ownerId` is now
  inaccessible over HTTP. This is intended, and host applications creating sessions
  programmatically must set an owner.
* Neutral: Option C remains available later. It would make canonicality a type
  guarantee rather than an adapter convention, at the cost of changing the Actions'
  public signatures.

## Implementation Plan

* **Affected paths**: `src/Modules/Chunking/Infrastructure/Http/Controllers/ChunkUploadController.php`,
  `src/Modules/Chunking/Infrastructure/Http/Requests/CompleteChunkRequest.php`,
  `routes/api.php`, `src/Modules/Chunking/Application/Actions/{InitiateChunkSession,ReassembleFile,CancelChunkSession}Action.php`.
* **Pattern to follow**: any endpoint accepting a session identifier resolves it through
  `canonicalSessionId()` as its first action, and uses only that value afterwards. Any
  Action that resolves a session derives its side effects from
  `$session->sessionId->value`.
* **Compliance surface**: `docs/architecture/data-transformations.md`. Each row records
  where a value is validated, normalised and first trusted. A change that moves any of
  those three points is incomplete until its row moves with it, and no row may have its
  *first trusted* point precede its *normalised* point — that inversion is this defect,
  stated as a checkable condition.
* **Tests**: `tests/Feature/Security/Vuln16SessionIdNormalizationRegressionTest.php` and
  `tests/Feature/Security/Vuln17OwnershipFailsClosedRegressionTest.php`.

### Verification

- [x] A foreign caller sending an upper-case session id receives 403 on `/upload`,
      `/status`, `/complete` and `/cancel`, and no attacker bytes reach the victim's
      staging directory.
- [x] The legitimate owner sending an upper-case session id still receives 200.
- [x] A malformed session id yields 404, never 500.
- [x] `/complete` rejects a non-UUID `session_id` with 422.
- [x] A session with a `null` owner is denied on all four endpoints, and is not
      surrendered by fingerprint reuse.
- [x] Ownership failures still reach the log through the exception's `report()` hook.
- [x] Both regression suites were run against the pre-fix code and fail there — a
      passing test on fixed code proves nothing on its own.

## Pros and Cons of the Options

### Option A — Reject non-canonical input in `SessionId`

* Good, eliminates the class of bug outright: if only one form is accepted, two forms
  cannot disagree.
* Bad, breaks RFC 4122, which states UUIDs are case-insensitive on input. Existing
  clients that upper-case identifiers would start receiving 422.
* Bad, pushes the fix into a value object shared by non-HTTP callers, for a defect that
  is specific to the HTTP adapter's ordering.

### Option B — Normalise at the adapter boundary (chosen)

* Good, fixes the actual root cause: the ordering of normalisation relative to
  authorization.
* Good, keeps input tolerance where the standard requires it.
* Good, gives one named place where the rule lives, which the tests and the compliance
  surface can both point at.
* Bad, remains a convention within the adapter: a new endpoint could forget to call it.
  Mitigated by the regression tests covering all four endpoints, and by the rule being
  written down here and in the architecture docs.

### Option C — Type the Actions on `SessionId`

* Good, the compiler enforces what Option B enforces by convention.
* Bad, changes the public signature of every Action, which host applications may call
  directly — a breaking change for a fix that must ship now.
* Neutral, can supersede this ADR later without undoing any of it.

## More Information

Found by the final offensive audit on 2026-09-08 (AF-001, severity HIGH, blocking) with
a working proof of concept: control 403 against the exact identifier, 200 against the
upper-cased one, and the attacker's bytes confirmed on disk inside the victim's session
directory. AF-006 (fail-open ownership) and AF-010 (raw identifier reaching filesystem
paths) are the same defect at different points in the same flow and are resolved here.

Relates to [ADR-0002](0002-adopt-immutable-response-envelope.md): the envelope keeps
`owner_id` out of every response, so the identifier this decision protects is never
echoed back to a caller in the first place.

### 2026-09-10 — extended to the caller identity itself

This decision originally normalised the **session** identifier at the boundary and left
the **caller** identifier a raw string. `ChunkSession` held `ownerId` as `?string` while
the `<scheme>:<value>` shape it depended on lived in an infrastructure class, so the
aggregate persisted a value whose format it did not own and could not enforce — the same
"normalised somewhere else, trusted here" arrangement this ADR exists to remove, one
field over.

`Core\ValueObjects\SessionOwner` now owns that shape and is built at the adapter
boundary, exactly where `SessionId` is. The scheme is not an enum, because
`ResolvesCallerIdentity` is an extension point and a consumer must be able to name its
own (`tenant:7:user:42` is valid). What is enforced is that a scheme exists at all, which
is what keeps the user whose id is `1.2.3.4` from sharing an owner and a rate-limit
bucket with the caller arriving from that address — a separation the CHANGELOG promised
when AF-004 was fixed and nothing verified until now.

The fail-closed comparison moved with it, from the controller to
`ChunkSession::isOwnedBy()`. Ownership had two readers, the HTTP guard and fingerprint
reuse, and AF-006 was those two disagreeing; there is now one answer on the aggregate.

**Consequence for consumers**: a custom `ResolvesCallerIdentity` returning a bare
identifier now fails with a server error naming the binding to fix, rather than silently
producing sessions that belong to nobody. Documented in the README.
