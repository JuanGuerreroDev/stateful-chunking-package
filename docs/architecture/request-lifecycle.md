# Request lifecycle: the five endpoints

One sequence diagram per endpoint, each annotated with **the exact point where the
authorization decision is made**. That annotation is the reason this document exists:
the package's ownership guard is correct in isolation, and what breaks it is *when* it
runs relative to validation and normalisation.

> **State of this document**: describes `main` at `3233508`, *before* the AF-001…AF-010
> remediation. Steps marked **⚠** are known-open findings from
> `docs/audits/scans/2026-09-08_final_offensive_audit.md`; each remediation PR updates
> the affected diagram. See [`data-transformations.md`](data-transformations.md) for the
> per-value view of the same seams.

## Authorization at a glance

| Endpoint | Input validated by | Session id validated? | Authorization point | Verdict |
| :--- | :--- | :---: | :--- | :--- |
| `POST /initiate` | `InitiateChunkRequest` | n/a (generated) | fingerprint reuse decides whether you are handed an existing session | ⚠ AF-005, AF-006 |
| `POST /upload` | `UploadChunkRequest` | yes, regex `/i` | `assertSessionOwnership()` on the **raw** id, before normalisation | ⚠ **AF-001 bypass** |
| `GET /status/{id}` | nothing | **no** | `assertSessionOwnership()` after the Action already resolved it | ⚠ AF-002, AF-009 |
| `POST /complete` | inline `required\|string` | **no** | `assertSessionOwnership()` on the raw id | ⚠ AF-002, AF-010 |
| `DELETE /cancel/{id}` | nothing | **no** | `assertSessionOwnership()` on the raw id | ⚠ AF-002, AF-010 |

Every route additionally carries its own rate limiter
(`throttle:stateful-chunking-<op>`) plus the group middleware from
`config('stateful-chunking.routes.middleware')`, which defaults to `['api']`.

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
        Note over AC: ⚠ AF-005 no status check, and the newly<br/>declared name/size/hash are ignored<br/>⚠ AF-006 a null owner is treated as public
    else no hit
        AC->>AC: SessionId::generate() + new ChunkSession
        AC->>RP: saveSession() + fingerprint index
        AC->>EV: ChunkSessionInitiated
    end
    AC-->>CT: ChunkSession
    CT->>CT: log session_id, file_name, owner_id, ip
    CT-->>CL: 201 ChunkingResponse::sessionInitiated<br/>allowlist projection, owner_id withheld
```

## 2. `POST /upload` — the seam that AF-001 exploits

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

    rect rgb(253, 232, 232)
        CT->>RP: getSession($rawSessionId) ← un-normalised
        RP-->>CT: null when the id case differs
        CT->>CT: assertSessionOwnership(null) → returns early
        Note over CT: ⚠ AF-001 the guard reads null as<br/>"no session to protect" when it really means<br/>"I looked it up wrong". No 403, and no<br/>UnauthorizedSessionAccessException audit entry.
    end

    CT->>CT: resolve content: file → input('file') → raw body
    Note over CT: ⚠ AF-008 trim() rejects an all-NUL raw chunk<br/>⚠ VULN-SEC-001 body is in memory before the size check
    CT->>CT: reject if empty (422) or > chunk_size × 1.1 (413)

    rect rgb(231, 246, 236)
        CT->>AC: UploadChunkDTO — SessionId VO lowercases HERE
        AC->>RP: getSession($dto->sessionId->value) → FOUND
    end

    AC->>AC: assertChunkIndexWithinBounds()
    alt chunk already completed
        AC->>AC: re-verify hash, return session (idempotent)
    else new chunk
        AC->>AC: assertWithinByteBudget()
        Note over AC: ⚠ AF-007 read outside the lock that<br/>increments it — check-then-act
        AC->>ST: storeChunk() — SHA-256 verified, constant time
        AC->>RP: updateChunkStatus(completed, bytes) — inside the lock
        Note over AC,ST: on failure: deleteChunk() rollback
    end
    AC-->>CT: updated ChunkSession
    CT-->>CL: 200 ChunkingResponse::chunkUploaded
```

The two shaded blocks are the defect. The ownership decision happens in the red block
against the raw string; the normalisation that makes the lookup succeed happens in the
green block, afterwards. **The invariant PR 1 installs**: the identifier is normalised
exactly once, at the adapter boundary, *before* any authorization decision.

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
    Note over CT: ⚠ no FormRequest: no authorize(),<br/>and the route parameter is unvalidated (AF-002, VULN-SEC-008)
    CT->>AC: handle($sessionId)
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
    Note over CT: ⚠ inline validate(required|string) —<br/>no UUID format check (VULN-SEC-007)
    CT->>RP: getSession($sessionId)
    CT->>CT: assertSessionOwnership()
    CT->>AC: handle($sessionId)

    rect rgb(231, 246, 236)
        AC->>RP: withSessionLock() — serialises reassembly (LIVE-002 fix)
        AC->>RP: getSession() → 404 if already consumed
        AC->>AC: isComplete() → 409 with pending_chunks
        AC->>ST: reassembleFile()
        Note over ST: streams chunks, verifies total SHA-256,<br/>deletes the assembled file on mismatch,<br/>then purges the temp chunks<br/>⚠ AF-010 path built from the raw request string
        AC->>TK: generateToken() — AES-256-CBC + HMAC, own TTL
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
