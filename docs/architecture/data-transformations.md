# Data transformations: validate, normalise, trust

For every value that crosses the package's boundary, this table records four moments:
where it **enters**, where it is **validated**, where it is **normalised**, and where
the code starts **trusting** it. Most defects in this package have been a disagreement
between the last two.

> **State of this document**: describes `main` at `3233508`, *before* the AF-001…AF-010
> remediation. Rows marked **⚠** carry open findings from
> `docs/audits/scans/2026-09-08_final_offensive_audit.md`.

## The rule this table exists to enforce

> **A value must be normalised before it is trusted, and validated before it is
> normalised. Never the other way round.**

That sentence is checkable against the table by eye, and it is what makes an entire
class of bug visible rather than clever. Three of the ten audit findings are simply rows
where the order is wrong:

| Finding | The inversion |
| :--- | :--- |
| **AF-001** | `session_id` is *trusted* (ownership decision) before it is *normalised* (`strtolower`) |
| **AF-004** | `owner_id` is *derived twice*, by two different implementations, one of which never works |
| **AF-010** | `session_id` reaches a filesystem path having *never* been normalised |

None of them is visible reading a single file. All three are visible reading one table.

---

## The table

### `session_id` ⚠

| | |
| :--- | :--- |
| **Enters as** | raw string — JSON body on `/upload` and `/complete`, route parameter on `/status` and `/cancel` |
| **Validated at** | `UploadChunkRequest:33` — UUID regex, **case-insensitive** (`/i`). `/complete`: `required\|string` only. `/status`, `/cancel`: **nothing** |
| **Normalised at** | `SessionId::__construct` → `strtolower()`, reached only via `UploadChunkDTO::fromArray` — so **only on `/upload`** |
| **First trusted at** | the ownership comparison, the cache key `chunk_session:<id>`, and the filesystem paths `chunks_temp/<id>/` and `uploads/<id>/` |
| **Status** | **AF-001**: on `/upload` the trust point precedes the normalisation point. VULN-SEC-007/008: the other three endpoints never check the format. AF-010: the raw request string, not the resolved entity's id, builds the paths |

### `chunk_index`

| | |
| :--- | :--- |
| **Enters as** | integer, request body |
| **Validated at** | `UploadChunkRequest:34` — `integer`, `min:0`, **no upper bound** |
| **Normalised at** | cast to `int` in `UploadChunkDTO::fromArray` |
| **First trusted at** | `ChunkSession::assertChunkIndexWithinBounds()` — the upper bound lives on the aggregate root, not in the request |
| **Status** | OK. Enforcing the bound on the aggregate is why no use case can bypass it, and `chunkPath()` interpolates with `%d`, so the index can never widen a path |

### `chunk_hash` ⚠

| | |
| :--- | :--- |
| **Enters as** | string, request body |
| **Validated at** | `UploadChunkRequest:35` — `/^[a-f0-9]{64}$/i` |
| **Normalised at** | `ChunkHash::__construct` → `trim()` + `strtolower()` |
| **First trusted at** | `LocalStorageAdapter::storeChunk()` — `hash_equals()` against the computed digest, constant time |
| **Status** | **VULN-SEC-004**: the comparison is wrapped in `strlen($chunkHash) >= 8 && strlen($chunkHash) === 64`, so a hash of any other length **skips verification silently**. Unreachable over HTTP (both the regex and the VO force 64), but it is defence-in-depth that does not defend, and a mutation removing the check would pass the suite |

### `total_hash` ⚠

| | |
| :--- | :--- |
| **Enters as** | string, request body on `/initiate` |
| **Validated at** | `InitiateChunkRequest:129` — same 64-hex regex |
| **Normalised at** | `ChunkHash` VO |
| **First trusted at** | `LocalStorageAdapter::reassembleFile()` — `hash_file()` + `hash_equals()`; on mismatch the assembled file is unlinked before throwing |
| **Status** | **VULN-SEC-005**: same conditional as `chunk_hash` (`strlen($expectedTotalHash) === 64`) |

### `file_name`

| | |
| :--- | :--- |
| **Enters as** | string, request body on `/initiate` — the only endpoint that accepts it |
| **Validated at** | `InitiateChunkRequest:48-101` — `max:255`, charset `^[a-zA-Z0-9._-]+$`, then a closure: dot-file ban, trailing dot/space ban, at least one extension segment, optional whitelist, and **every** segment after the stem checked against the forbidden list (double-extension defence) |
| **Normalised at** | never — stored verbatim; `basename()` is applied at reassembly time |
| **First trusted at** | the final path `uploads/<sessionId>/<basename(fileName)>` |
| **Status** | OK for traversal, but note *which* control earns that: the anchored charset excludes `/` and `\`, so no separator can appear — it does **not** exclude `..`, since dots are in the charset. A bare `..` is rejected by the dot-file guard (`str_starts_with($strValue, '.')`), and `basename()` is belt-and-braces. Relaxing that guard to allow leading-dot names would re-open `file_name = ".."`, whose assembled path `uploads/<sessionId>/..` resolves to the parent directory. **LIVE-004** is an accepted trade-off: validation is by declared extension only, with no content or real-MIME inspection. The staged-token pattern keeps the file outside the webroot, so verifying the real type is the consumer's job before it moves the file |

### `fingerprint` ⚠

| | |
| :--- | :--- |
| **Enters as** | string, nullable, request body on `/initiate` |
| **Validated at** | `InitiateChunkRequest:130` — `max:255` and nothing else; the content is arbitrary |
| **Normalised at** | never |
| **First trusted at** | the cache key `chunk_fingerprint:<fingerprint>` **and the decision to hand back an existing session** |
| **Status** | **AF-005**: reuse checks neither the session's status nor whether the newly declared `file_name` / `file_size` / `total_chunks` / `total_hash` match, so a second `/initiate` with the same fingerprint returns 201 while silently binding the *previous* file. Cross-driver note: `chunk_fingerprint:` (18 chars) + 255 exceeds Memcached's 250-byte key limit and the database cache driver's default 255-char key column, so resume-by-fingerprint fails silently above ~232 characters |

### `content` — the chunk bytes ⚠

| | |
| :--- | :--- |
| **Enters as** | multipart upload, a `file` string input, or the raw request body — resolved in that order |
| **Validated at** | `UploadChunkRequest:36` (`file` rule, `max:` in KB), then re-checked in the controller: `strlen($content) > chunk_size × 1.1` → 413 |
| **Normalised at** | n/a — opaque bytes |
| **First trusted at** | `storeChunk()` writes it, but only after the SHA-256 comparison |
| **Status** | **VULN-SEC-001**: the body is fully buffered into a PHP string *before* the size check, so the guard limits what is stored, not what is allocated. **AF-008**: the emptiness guard uses `trim($content) === ''`, and `trim()` strips `\0` — so an all-NUL raw-body chunk (sparse file, disk image, padded binary) is rejected as "empty" |

### `file_size` and `total_chunks`

| | |
| :--- | :--- |
| **Enters as** | integers, request body on `/initiate` |
| **Validated at** | `InitiateChunkRequest:102-128` — `file_size` in `1..max_file_size_bytes`; `total_chunks` bounded **both ways** against `ceil(file_size / chunk_size)` with one chunk of slack, capped by `max_total_chunks` |
| **Normalised at** | cast to `int` |
| **First trusted at** | `ChunkSession::byteBudget()` and `assertChunkIndexWithinBounds()` |
| **Status** | The two-sided bound is the **LIVE-001** fix: it is what stops a 1-byte declaration from staging gigabytes. **AF-007**: `assertWithinByteBudget()` reads `uploadedBytes` outside the lock that increments it, so N concurrent uploads can each pass the same check |

### `owner_id` ⚠ — derived, never client input

| | |
| :--- | :--- |
| **Enters as** | not an input. Derived from the request's authenticated user, or its IP |
| **Validated at** | n/a |
| **Normalised at** | **twice, differently.** `ChunkUploadController::resolveCurrentOwnerId()` uses `$user->getAuthIdentifier()` → `user:<id>` \| `ip:<addr>`. `StatefulChunkingServiceProvider::configureRateLimiting()` independently uses `property_exists($user, 'id')` → `<id>` \| `<ip>` |
| **First trusted at** | the ownership comparison in `assertSessionOwnership()`, and the rate-limit bucket |
| **Status** | **AF-004**: `property_exists()` inspects *declared* properties, and Eloquent's `id` lives in `$attributes` behind `__get()` — so the limiter's branch is **always false** and all throttling is per-IP, contradicting README:139. **AF-006**: a `null` owner is treated as "belongs to everyone" by both the guard and fingerprint reuse, i.e. authorization fails **open**. Never echoed to clients: `ChunkingResponse::publicSessionData()` is an allowlist and omits it |

### `upload_token`

| | |
| :--- | :--- |
| **Enters as** | minted by the package at `/complete`; re-enters later from the consumer's own request |
| **Validated at** | `Rules\ValidUploadToken` → `StatefulChunkingService::resolveToken()` → MAC verification, JSON decode, then `StagedFileDTO::isValid()` (the `is_valid` flag **and** `expiresAt`) |
| **Normalised at** | n/a — opaque ciphertext |
| **First trusted at** | the consumer resolves `temp_path` and `disk` from the decrypted payload |
| **Status** | OK. `Crypt::encryptString` is AES-256-CBC with encrypt-then-MAC, so tampering with `temp_path` or `disk` fails closed and there is no padding oracle. The TTL is enforced and deliberately shorter than the session's (7200s vs 6h), so a leaked token dies first. Never logged — `ChunkUploadController::complete()` explicitly `unset()`s it from the audit context |

---

## How to use this table

**When adding an input**: add its row before writing the code. If you cannot name the
normalisation point, there probably isn't one, and the trust point is doing the
normalising implicitly — which is how AF-001 happened.

**When adding a validation rule**: check whether it belongs in the FormRequest (request
shape), the value object (format and canonical form), or the aggregate root (business
invariant). The three are not interchangeable: `chunk_index` shows why the upper bound
belongs on `ChunkSession` and not in `UploadChunkRequest` — the request only guards one
entry path, the aggregate guards all of them.

**When reviewing a PR that touches authorization**: read the *First trusted at* row and
ask what has already happened to the value by then. That single question is the review
that would have caught AF-001.

**Enforcement**: ADR-0003 names this document as its compliance surface. A change that
moves where a value is validated, normalised or trusted is incomplete until its row
here moves with it.
