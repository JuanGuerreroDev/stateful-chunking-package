# Request lifecycle: the five endpoints

One sequence diagram per endpoint, each annotated with **the exact point where the
authorization decision is made**. That annotation is the reason this document exists:
the package's ownership guard is correct in isolation, and what breaks it is *when* it
runs relative to validation and normalisation.

> **State of this document**: every audit finding annotated here is **resolved**, except
> AF-009, which the audit itself recorded as a note rather than an action. See
> [ADR-0003](../decisions/0003-normalise-identity-at-the-adapter-boundary.md). Steps
> still marked **⚠** are accepted risks, named in place; each PR that changes behaviour
> updates the diagrams it touches. Finding identifiers refer to the private security
> review under `docs/audits/`, which is not published with the package. See
> [`data-transformations.md`](data-transformations.md) for the per-value view of the
> same seams.

## Authorization at a glance

Every endpoint that accepts a session identifier canonicalises it **first**, through
`ChunkUploadController::canonicalSessionId()`, and only then makes an authorization
decision. That ordering is the invariant ADR-0003 installs: before it, the guard ran on
the raw request value while the DTO normalised it afterwards, and the disagreement was a
bypass.

| Endpoint | Input validated by | Session id validated? | Authorization point | Verdict |
| :--- | :--- | :---: | :--- | :--- |
| `POST /initiate` | `InitiateChunkRequest` | n/a (generated) | fingerprint reuse, gated on owner, status and declaration | resolved |
| `POST /upload` | `UploadChunkRequest` | yes, UUID regex | on the **canonical** id, before the DTO | ok |
| `GET /status/{id}` | route pattern | yes, route pattern | after the Action resolved it | ⚠ AF-009 |
| `POST /complete` | `CompleteChunkRequest` | yes, UUID regex | on the canonical id | ok |
| `DELETE /cancel/{id}` | route pattern | yes, route pattern | on the canonical id | ok |

Every route carries the group middleware from
`config('stateful-chunking-upload.routes.middleware')`, which defaults to `['api']`, and — **only
while `rate_limits.enabled` is true** — its own limiter, `throttle:stateful-chunking-upload-<op>`.
That flag is worth knowing about: the limiter is the mitigating control several accepted
audit findings lean on to bound storage amplification and orphan-chunk accumulation to a
throughput rather than an unbounded quantity. Turning it off does not just relax a quota,
it removes that bound.

---

## 1. `POST /initiate`

```mermaid
sequenceDiagram
    autonumber
    participant CL as Client
    participant RL as throttle initiate<br/>10/min
    participant FR as InitiateChunkRequest
    participant CT as ChunkUploadController
    participant AC as InitiateChunkSessionAction
    participant RP as StateRepositoryInterface
    participant EV as Domain events

    CL->>RL: file_name, file_size, total_chunks,<br/>total_hash, fingerprint
    RL->>FR: within quota
    Note over FR: file_name: charset regex, dot-file ban,<br/>trailing dot/space ban, multi-segment<br/>extension inspection, optional whitelist
    Note over FR: total_chunks bounded BOTH ways against<br/>file_size — the LIVE-001 amplification fix
    FR->>CT: validated()
    CT->>CT: resolveCurrentOwnerId() → user:ID | ip:ADDR
    CT->>AC: InitiateSessionDTO
    AC->>RP: findSessionByFingerprint()
    alt fingerprint hit and owner matches
        RP-->>AC: existing session
        Note over AC: reuse requires all of: the caller owns it,<br/>status is PENDING or UPLOADING, and file_name,<br/>file_size, total_chunks and total_hash all match.<br/>A null owner belongs to nobody, not to everybody name/size/hash are still ignored
    else no hit
        AC->>AC: SessionId::generate() + new ChunkSession
        AC->>RP: saveSession() + fingerprint index
        AC->>EV: ChunkSessionInitiated
    end
    AC-->>CT: ChunkSession
    CT->>CT: log session_id, file_name, owner_id, ip
    CT-->>CL: 201 ChunkingResponse::sessionInitiated<br/>allowlist projection, owner_id withheld
```

## 2. `POST /upload` — the seam AF-001 exploited

```mermaid
sequenceDiagram
    autonumber
    participant CL as Client
    participant RL as throttle upload<br/>120/min
    participant FR as UploadChunkRequest
    participant CT as ChunkUploadController
    participant RP as StateRepositoryInterface
    participant AC as UploadChunkAction
    participant ST as FileStorageInterface

    CL->>RL: session_id, chunk_index, chunk_hash, file
    RL->>FR: within quota
    Note over FR: session_id UUID regex is CASE-INSENSITIVE (/i)<br/>chunk_index has min:0 but no max
    FR->>CT: validated()

    rect rgb(231, 246, 236)
        CT->>CT: canonicalSessionId() — SessionId normalises HERE,<br/>once, before any authorization decision
        CT->>RP: getSession($sessionId) ← canonical
        CT->>CT: assertSessionOwnership() → 403 on mismatch,<br/>and the attempt is audited
        Note over CT: The canonical value is written back into<br/>$validated, so the DTO below cannot derive a<br/>different id than the one just authorized.
    end

    CT->>CT: resolve content: file → input('file') → raw body
    Note over CT: empty means no bytes arrived, not "looks like whitespace"<br/>⚠ VULN-SEC-001 body is in memory before the size check
    CT->>CT: reject if empty (422) or > chunk_size × 1.1 (413)

    CT->>AC: UploadChunkDTO — already canonical
    AC->>RP: getSession($dto->sessionId->value) — same id as authorized

    AC->>AC: assertChunkIndexWithinBounds()
    alt chunk already completed
        AC->>AC: re-verify hash, return session (idempotent)
    else new chunk
        AC->>AC: assertWithinBudget() — early exit, outside the lock
        Note over AC: this read cannot be the guarantee, so the budget<br/>travels with the state update and is re-checked there
        AC->>ST: storeChunk() — SHA-256 verified, constant time
        AC->>RP: updateChunkStatus(completed, bytes, budget)
        Note over RP: budget re-verified against the state read<br/>inside the same lock that increments the counter
        Note over AC,ST: on failure: deleteChunk() rollback
    end
    AC-->>CT: updated ChunkSession
    CT-->>CL: 200 ChunkingResponse::chunkUploaded
```

The shaded block is where the defect used to live. Normalisation and the ownership
decision now happen there together, in that order, and everything downstream is handed
the same value. Previously the decision was made in that position against the raw string
while `SessionId` normalised it two steps later, inside the DTO — so an upper-case UUID
missed the guarded cache lookup, the guard read `null` as "no session to protect", and
the Action then found the victim's session anyway.

## 3. `GET /status/{sessionId}`

```mermaid
sequenceDiagram
    autonumber
    participant CL as Client
    participant RL as throttle status<br/>60/min
    participant CT as ChunkUploadController
    participant AC as GetChunkStatusAction
    participant RP as StateRepositoryInterface

    CL->>RL: GET /status/{sessionId}
    RL->>CT: within quota
    Note over CT: route pattern already rejected any non-UUID<br/>authentication, if any, was applied by the<br/>consumer's route middleware
    CT->>CT: canonicalSessionId()
    CT->>AC: handle($canonicalId)
    AC->>RP: getSession()
    alt missing or expired
        RP-->>AC: null
        AC-->>CL: 404 SessionNotFoundException<br/>self-renders and self-audits
    else found
        RP-->>AC: ChunkSession
        AC-->>CT: session
        CT->>CT: assertSessionOwnership()
        Note over CT: ⚠ AF-009 403 here vs 404 above is an<br/>existence oracle — accepted, UUIDv4 entropy
        CT-->>CL: 200 data only, no message key
    end
```

## 4. `POST /complete`

```mermaid
sequenceDiagram
    autonumber
    participant CL as Client
    participant RL as throttle complete<br/>20/min
    participant CT as ChunkUploadController
    participant RP as StateRepositoryInterface
    participant AC as ReassembleFileAction
    participant ST as FileStorageInterface
    participant TK as StatefulChunkingService

    CL->>RL: session_id
    RL->>CT: within quota
    Note over CT: CompleteChunkRequest enforces the UUID shape,<br/>then canonicalSessionId() normalises it
    CT->>RP: getSession($sessionId)
    CT->>CT: assertSessionOwnership()
    CT->>AC: handle($sessionId)

    rect rgb(231, 246, 236)
        AC->>RP: withSessionLock() — serialises reassembly (LIVE-002 fix)
        AC->>RP: getSession() → 404 if already consumed
        AC->>AC: isComplete() → 409 with pending_chunks
        AC->>ST: reassembleFile()
        Note over ST: streams chunks, verifies total SHA-256,<br/>deletes the assembled file on mismatch,<br/>then purges the temp chunks<br/>path derives from $session->sessionId->value,<br/>never from the request string
        AC->>TK: generateToken() — authenticated encryption<br/>under the app's cipher, own TTL
        AC->>RP: deleteSession()
        AC->>AC: dispatch FileReassembled
    end

    AC-->>CT: result array
    CT->>CT: log result MINUS upload_token
    CT-->>CL: 200 ChunkingResponse::fileReassembled<br/>path withheld unless expose_server_paths
```

## 5. `DELETE /cancel/{sessionId}`

```mermaid
sequenceDiagram
    autonumber
    participant CL as Client
    participant RL as throttle cancel<br/>20/min
    participant CT as ChunkUploadController
    participant RP as StateRepositoryInterface
    participant AC as CancelChunkSessionAction
    participant ST as FileStorageInterface

    CL->>RL: DELETE /cancel/{sessionId}
    RL->>CT: within quota
    CT->>RP: getSession($sessionId)
    CT->>CT: assertSessionOwnership()
    CT->>AC: handle($sessionId)
    alt session exists
        AC->>ST: deleteTemporaryChunks()
        AC->>RP: deleteSession()
        AC->>AC: dispatch ChunkSessionCancelled
    else already gone
        Note over AC: no-op — cancel is idempotent and<br/>reports 200 either way, so it leaks no<br/>existence information
    end
    AC-->>CT: void
    CT-->>CL: 200 message only, no data key
```

---

## How failures are rendered

The controller carries **no** `try`/`catch`. That is deliberate and it is what keeps it
a thin adapter:

```mermaid
graph LR
    A["Action or adapter<br/>throws"] --> B{"ChunkingException?"}
    B -->|yes| C["render(): JsonResponse with the<br/>client-safe publicMessage + status"]
    B -->|yes| D["report(): logs getMessage() +<br/>structured context on the package channel"]
    B -->|no| E["Laravel's global handler<br/>sanitises in production"]
```

Each subclass fixes its own HTTP status and its own public message. The detailed
message and the context array are **server-side only** — they feed the log, never the
response body. Adding a failure mode means adding a subclass, not an `if` in the
controller.

| Exception | Status |
| :--- | :---: |
| `SessionNotFoundException` | 404 |
| `UnauthorizedSessionAccessException` | 403 |
| `SessionNotReadyException` | 409 |
| `ChunkIntegrityException` | 422 |
| `ChunkIndexOutOfBoundsException` | 422 |
| `UploadBudgetExceededException` | 413 |
| `StorageFailureException` | 500 |

The two request-shape guards in the controller (empty payload, oversized payload) are
*not* domain failures, so they return `ChunkingResponse::inputError()` instead — a
message-only envelope. That distinction is the line between "your request is malformed"
and "your request is well-formed but the domain refuses it".
