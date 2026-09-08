---
status: 'accepted'
date: 2026-09-08
decision-makers: 'Juan Manuel Guerrero Cañón (maintainer)'
consulted: 'docs/proposals/ai_ready_a2a_response_envelope_proposal.md'
informed: 'Package consumers of the Chunking HTTP API'
---

# Adopt an immutable response envelope for the Chunking HTTP API

## Context and Problem Statement

The `ChunkUploadController` used to build every success response inline —
`response()->json([...])` with hand-assembled arrays and `->toArray()` calls
spread across all five actions (`initiate`, `upload`, `status`, `complete`,
`cancel`). That coupled HTTP presentation to request orchestration in one class
and, more importantly, made the response's data projection **implicit**: whatever
a `ChunkSession` or a reassembly result happened to expose leaked straight to the
client.

Two concrete leaks resulted. Sessions carry an `owner_id` — a caller identifier
such as `user:42` or `ip:1.2.3.4` (PII / an IDOR-relevant value) — which was
echoed back to clients. And the reassembly result carried the assembled file's
real server `path` / `relative_path`, disclosing filesystem layout. There was no
single place to enforce "what is safe to return", so any new field on the domain
entity risked becoming a new leak.

The first half of the A2A proposal
(`docs/proposals/ai_ready_a2a_response_envelope_proposal.md`) called for
extracting the response shape into a dedicated presentation object. How should
success responses be built so that the controller stays a thin adapter, the
public projection is an explicit and auditable allowlist, and the **existing API
contract is preserved byte-for-byte** for current consumers?

## Decision Drivers

- **Preserve the established contract exactly.** The Chunking API's response shape is already published: envelope `{message, data}`, the specific status codes, and two historical asymmetries — `GET /status` returns `data` only (no `message`), `DELETE /cancel` returns `message` only (no `data`). None of this may drift.
- **PII / path non-disclosure by default.** `owner_id` must never be returned; server paths must be withheld unless an operator opts in.
- **Thin controller.** Response construction is a presentation concern and does not belong in the request-handling class.
- **Respect the hexagonal boundary.** Domain and application layers stay framework-agnostic; anything touching `Illuminate\Http` lives in `Infrastructure/Http`.
- **Consistency with the existing self-rendering pattern.** Failures already self-render via the `ChunkingException` hierarchy; successes should mirror that so both halves of every response are built outside the controller.
- **Static-analysis clean.** Must pass PHPStan level 10 (typed array access, no mixed leakage).

## Considered Options

- **Option A — Status quo**: keep `response()->json()` + `->toArray()` inline in the controller.
- **Option B — Laravel API Resources** (`JsonResource` / resource collections).
- **Option C — Immutable envelope DTO** implementing `Responsable`, with named constructors and an explicit allowlist projection.

## Decision Outcome

Chosen option: **Option C — Immutable envelope DTO (`ChunkingResponse`)**, because
it is the only option that puts the PII / path policy in a single auditable place,
keeps the controller a thin adapter, and mirrors the existing `ChunkingException`
self-rendering pattern — while preserving the published contract exactly (verified
live end-to-end against testbench across all five endpoints, including both
contract asymmetries and the paths-opt-in branch).

### Consequences

- Good, because the public projection is now one explicit allowlist (`publicSessionData()`); adding a field to `ChunkSession` no longer risks leaking it.
- Good, because `owner_id` is absent from every response and server paths are withheld unless `expose_server_paths` is enabled.
- Good, because the controller returns `Responsable` and contains zero `response()->json()` / `->toArray()` calls; error and success shaping both live outside it.
- Good, because it lays the groundwork for the A2A proposal without committing to the full protocol yet.
- Bad, because the response contract deliberately changed for consumers that relied on `owner_id` or on `path`/`relative_path` being present by default (this is intended; those were leaks).
- Neutral, because there is no byte-for-byte snapshot test of the *pre-refactor* JSON; equivalence rests on field-by-field porting plus the passing suite and the live E2E ratification.

## Implementation Plan

This decision is already implemented on `main`. The plan below is the spec any
future change to Chunking responses must follow.

- **Affected paths**:
  - `src/Modules/Chunking/Infrastructure/Http/Responses/ChunkingResponse.php` — the envelope (immutable, `final`, private constructor + named constructors).
  - `src/Modules/Chunking/Infrastructure/Http/Controllers/ChunkUploadController.php` — every action returns `Illuminate\Contracts\Support\Responsable` via a `ChunkingResponse::*` named constructor.
  - `tests/Feature/ResponseEnvelopeTest.php` — pins the projection policy.
  - `config/stateful-chunking.php` — `expose_server_paths` flag.
- **Dependencies**: none added; uses `illuminate/contracts` (`Responsable`), already required.
- **Patterns to follow**:
  - One named constructor per outcome (`sessionInitiated`, `chunkUploaded`, `sessionStatus`, `fileReassembled`, `sessionCancelled`, `inputError`); the controller names the outcome, the envelope owns the shape.
  - Project sessions **only** through `publicSessionData()` — an explicit allowlist. Server-derived or caller-identifying fields (starting with `owner_id`) stay out.
  - Gate any server path behind `config('stateful-chunking.expose_server_paths', false)`.
  - Omit null `message` / `data` keys in `toResponse()` so the two contract asymmetries hold (`status` = data-only, `cancel` = message-only).
  - Use the typed `asString` / `asInt` / `asBool` helpers for array access so PHPStan level 10 stays clean.
  - Mirror `ChunkingException`: presentation lives in the response object, not the controller.
- **Patterns to avoid**:
  - Do not call `response()->json()`, `->toArray()`, or return `JsonResponse` directly from the controller.
  - Do not add `owner_id` (or any caller identifier) to a response projection.
  - Do not return real filesystem paths outside the `expose_server_paths` branch.
  - Do not log the `upload_token` (bearer credential): redact it before auditing, as `complete()` does.
- **Configuration**: `expose_server_paths` (bool, default `false`) → env `STATEFUL_CHUNKING_EXPOSE_SERVER_PATHS`.
- **Migration steps**: complete; done in one refactor with the contract held constant, so no consumer migration is required beyond the intended removal of `owner_id` / default paths.

### Verification

- [x] The controller contains no `response()->json()`, no `->toArray()`, and returns `Responsable` from every action.
- [x] `owner_id` is absent from `initiate`, `upload`, and `status` responses.
- [x] `path` / `relative_path` are absent from `complete` by default and present only when `expose_server_paths=true`.
- [x] `GET /status` returns `data` with no `message`; `DELETE /cancel` returns `message` with no `data`.
- [x] `complete` returns `upload_token` and `verified`, and the `upload_token` is redacted from logs.
- [x] `tests/Feature/ResponseEnvelopeTest.php` pins all of the above; Pest suite and PHPStan level 10 pass.

## Pros and Cons of the Options

### Option A — Status quo (inline `response()->json`)

- Good, because it is zero additional code and already worked.
- Neutral, because it keeps everything in one file.
- Bad, because the data projection is implicit — new entity fields leak automatically.
- Bad, because the PII / path policy is scattered across five actions with no single enforcement point.
- Bad, because it keeps presentation coupled to orchestration in the controller.

### Option B — Laravel API Resources (`JsonResource`)

- Good, because it is an idiomatic Laravel presentation layer.
- Good, because it separates shaping from the controller.
- Neutral, because it still requires deliberate allowlist discipline per resource.
- Bad, because it is oriented toward Eloquent-style models, not framework-agnostic domain entities/DTOs.
- Bad, because it does not mirror the existing `ChunkingException` self-rendering pattern, so success and failure would be shaped by two different mechanisms.

### Option C — Immutable envelope DTO (chosen)

- Good, because one `publicSessionData()` allowlist governs the whole projection.
- Good, because named constructors make each outcome explicit and the object immutable.
- Good, because it self-renders via `Responsable`, exactly mirroring `ChunkingException`.
- Good, because the path policy is a single config-gated branch.
- Neutral, because it is bespoke to this package rather than a framework feature.
- Bad, because it is a small amount of hand-written code to maintain.

## More Information

- **Source proposal**: `docs/proposals/ai_ready_a2a_response_envelope_proposal.md` (this ADR implements its first half).
- **Non-goal — full A2A protocol (deferred, YAGNI)**: agent discovery, capability negotiation, and the full semantic agent envelope are intentionally out of scope. There is no agent consumer today, and building the exact shape now would be speculative. **Revisit trigger**: when the first agent-to-agent consumer of this API appears, reopen the proposal and evolve `ChunkingResponse` toward it in a superseding ADR.
- **Non-goal — package-wide mandate**: this decision governs the **Chunking module's established HTTP API contract** only. Other/future modules are free to choose their own presentation strategy; extending the envelope pattern package-wide would need its own ADR.
- **Ratification**: all five endpoints were exercised live against testbench (happy path, partial `status`, and `complete` with `expose_server_paths` both off and on), confirming the contract and the security guarantees hold.
