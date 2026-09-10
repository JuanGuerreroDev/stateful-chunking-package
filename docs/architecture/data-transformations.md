# Data transformations: validate, normalise, trust

For every value that crosses the package's boundary, this table records four moments:
where it **enters**, where it is **validated**, where it is **normalised**, and where
the code starts **trusting** it. Most defects in this package have been a disagreement
between the last two.

> **State of this document**: every finding of the 2026-09-08 audit that this table
> tracks is now **resolved** — AF-001 through AF-008 and AF-010. Rows still marked **⚠**
> carry findings from earlier rounds (VULN-SEC-001, 004 and 005) that remain open by
> choice, and are named in their own rows.

## The rule this table exists to enforce

> **A value must be normalised before it is trusted, and validated before it is
> normalised. Never the other way round.**

That sentence is checkable against the table by eye, and it is what makes an entire
class of bug visible rather than clever. Three of the ten audit findings were simply rows
where the order was wrong:

| Finding | The inversion | State |
| :--- | :--- | :--- |
| **AF-001** | `session_id` was *trusted* (ownership decision) before it was *normalised* | closed by ADR-0003 |
| **AF-010** | `session_id` reached a filesystem path having *never* been normalised | closed by ADR-0003 |
| **AF-004** | `owner_id` was *derived twice*, by two implementations, one of which never worked | closed by one shared resolver |

None of them is visible reading a single file. All three are visible reading one table.
That is the argument for keeping it accurate: [ADR-0003](../decisions/0003-normalise-identity-at-the-adapter-boundary.md)
names this document as its compliance surface, so a change that moves where a value is
validated, normalised or trusted is not finished until its row moves too.

---

## The table

### `session_id`

| | |
| :--- | :--- |
| **Enters as** | raw string — JSON body on `/upload` and `/complete`, route parameter on `/status` and `/cancel` |
| **Validated at** | `UploadChunkRequest` and `CompleteChunkRequest` — the same case-insensitive UUID regex; `/status` and `/cancel` by route pattern, so a malformed id is a routing miss (404) and never reaches the controller |
| **Normalised at** | `ChunkUploadController::canonicalSessionId()` — once, at the adapter boundary, as the first act of every endpoint that accepts an identifier |
| **First trusted at** | the ownership comparison, the cache key `chunk_session:<id>`, and the filesystem paths `chunks_temp/<id>/` and `uploads/<id>/` — all reached **after** normalisation. Actions derive paths from `$session->sessionId->value`, not from the string they were called with |
| **Status** | OK. Closed by [ADR-0003](../decisions/0003-normalise-identity-at-the-adapter-boundary.md): AF-001 was this row with its last two lines in the wrong order, and AF-010 was the path being built from a value that had skipped the middle one |

### `chunk_index`

| | |
| :--- | :--- |
| **Enters as** | integer, request body |
| **Validated at** | `UploadChunkRequest`, rule `chunk_index` — `integer`, `min:0`, **no upper bound** |
| **Normalised at** | cast to `int` in `UploadChunkDTO::fromArray` |
| **First trusted at** | `ChunkSession::assertChunkIndexWithinBounds()` — the upper bound lives on the aggregate root, not in the request |
| **Status** | OK. Enforcing the bound on the aggregate is why no use case can bypass it, and `chunkPath()` interpolates with `%d`, so the index can never widen a path |

### `chunk_hash` ⚠

| | |
| :--- | :--- |
| **Enters as** | string, request body |
| **Validated at** | `UploadChunkRequest`, rule `chunk_hash` — `/^[a-f0-9]{64}$/i` |
| **Normalised at** | `ChunkHash::__construct` → `trim()` + `strtolower()` |
| **First trusted at** | `LocalStorageAdapter::storeChunk()` — `hash_equals()` against the computed digest, constant time |
| **Status** | **VULN-SEC-004**: the comparison is wrapped in `strlen($chunkHash) >= 8 && strlen($chunkHash) === 64`, so a hash of any other length **skips verification silently**. Unreachable over HTTP (both the regex and the VO force 64), but it is defence-in-depth that does not defend, and a mutation removing the check would pass the suite |

### `total_hash` ⚠

| | |
| :--- | :--- |
| **Enters as** | string, request body on `/initiate` |
| **Validated at** | `InitiateChunkRequest`, rule `total_hash` — same 64-hex regex |
| **Normalised at** | `ChunkHash` VO |
| **First trusted at** | `LocalStorageAdapter::reassembleFile()` — `hash_file()` + `hash_equals()`; on mismatch the assembled file is unlinked before throwing |
| **Status** | **VULN-SEC-005**: same conditional as `chunk_hash` (`strlen($expectedTotalHash) === 64`) |

### `file_name`

| | |
| :--- | :--- |
| **Enters as** | string, request body on `/initiate` — the only endpoint that accepts it |
| **Validated at** | `InitiateChunkRequest`, rule `file_name` — `max:255`, charset `^[a-zA-Z0-9._-]+$`, then a closure: dot-file ban, trailing dot/space ban, at least one extension segment, optional whitelist, and **every** segment after the stem checked against the forbidden list (double-extension defence) |
| **Normalised at** | never — stored verbatim; `basename()` is applied at reassembly time |
| **First trusted at** | the final path `uploads/<sessionId>/<basename(fileName)>` |
| **Status** | OK for traversal, but note *which* control earns that: the anchored charset excludes `/` and `\`, so no separator can appear — it does **not** exclude `..`, since dots are in the charset. A bare `..` is rejected by the dot-file guard (`str_starts_with($strValue, '.')`), and `basename()` is belt-and-braces. Relaxing that guard to allow leading-dot names would re-open `file_name = ".."`, whose assembled path `uploads/<sessionId>/..` resolves to the parent directory. **LIVE-004** is an accepted trade-off: validation is by declared extension only, with no content or real-MIME inspection. The staged-token pattern keeps the file outside the webroot, so verifying the real type is the consumer's job before it moves the file |

### `fingerprint`

| | |
| :--- | :--- |
| **Enters as** | string, nullable, request body on `/initiate` |
| **Validated at** | `InitiateChunkRequest`, rule `fingerprint` — `max:255` and nothing else; the content is arbitrary |
| **Normalised at** | never — it is an opaque client-chosen token |
| **First trusted at** | the cache key `chunk_fingerprint:<fingerprint>`, and **nowhere else**. It no longer decides whether a session is handed back: it only *proposes* a candidate, which `InitiateChunkSessionAction::isResumable()` then has to confirm |
| **Status** | OK. Closed by AF-005's fix: a candidate is resumed only when the caller owns it, its status is still `PENDING` or `UPLOADING`, **and** `file_name`, `file_size`, `total_chunks` and `total_hash` all match the new declaration. Before that, the fingerprint alone was enough to be handed a session describing a different file — 201 "Session initiated successfully" carrying the previous file's name and size, after which the second file's chunks overwrote the first's and `/complete` failed integrity verification for both. Cross-driver note, unchanged: `chunk_fingerprint:` (18 chars) + 255 exceeds Memcached's 250-byte key limit and the database cache driver's default 255-char key column, so resume-by-fingerprint fails silently above ~232 characters |

### `content` — the chunk bytes ⚠

| | |
| :--- | :--- |
| **Enters as** | multipart upload, a `file` string input, or the raw request body — resolved in that order |
| **Validated at** | `UploadChunkRequest`, rule `file` (`max:` in KB), then re-checked in the controller: `strlen($content) > chunk_size × 1.1` → 413 |
| **Normalised at** | n/a — opaque bytes |
| **First trusted at** | `storeChunk()` writes it, but only after the SHA-256 comparison |
| **Status** | **VULN-SEC-001** remains open: the body is fully buffered into a PHP string *before* the size check, so the guard limits what is stored, not what is allocated. **AF-008 is closed**: the guard now reads `$content === ''`. It used to read `trim($content) === ''`, and `trim()` strips `\0`, so an all-NUL raw-body chunk — ordinary in a sparse file, a disk image or a padded binary — was rejected as "empty" despite carrying a full payload and a valid hash. Emptiness means no bytes arrived, never that the bytes look like whitespace |

### `file_size` and `total_chunks`

| | |
| :--- | :--- |
| **Enters as** | integers, request body on `/initiate` |
| **Validated at** | `InitiateChunkRequest`, rules `file_size` and `total_chunks` — `file_size` in `1..max_file_size_bytes`; `total_chunks` bounded **both ways** against `ceil(file_size / chunk_size)` with one chunk of slack, capped by `max_total_chunks` |
| **Normalised at** | cast to `int` |
| **First trusted at** | `ChunkSession::byteBudget()` and `assertChunkIndexWithinBounds()` |
| **Status** | OK. The two-sided bound is the **LIVE-001** fix: it is what stops a 1-byte declaration from staging gigabytes. **AF-007 is closed**: the budget is re-verified inside the same critical section that increments the counter, through the optional `$byteBudget` argument to `updateChunkStatus()`. The check outside the lock is kept as an early exit but is no longer the guarantee — deciding there alone let N concurrent uploads of distinct indices all pass on one `uploadedBytes` snapshot, overshooting by up to (N-1) chunks |

### `owner_id` — derived, never client input

| | |
| :--- | :--- |
| **Enters as** | not an input. Derived from the request's authenticated user, or its IP |
| **Validated at** | n/a |
| **Normalised at** | `ResolvesCallerIdentity` — one port, resolved from the container per request and shared by the controller's ownership check and the provider's rate-limit key. The default `RequestCallerIdentity` returns `user:<id>` from `getAuthIdentifier()`, else `ip:<addr>`; a consumer may rebind it (tenant, API key, calling service) and both readers follow. The string is then wrapped in the `SessionOwner` value object at the adapter boundary, which is what **enforces** the `<scheme>:<value>` shape the namespacing depends on |
| **First trusted at** | `ChunkSession::isOwnedBy()`, reached from `assertSessionOwnership()` and from fingerprint reuse — one fail-closed comparison on the aggregate instead of two in the callers — and the rate-limit bucket |
| **Status** | OK. AF-004 was this row reading *"normalised twice, differently"*: the limiter had its own copy built on `property_exists($user, 'id')`, which is always false for an Eloquent model because `id` lives in `$attributes` behind `__get()` — so every authenticated caller was bucketed by IP and users behind one NAT ate each other's quota. AF-006 is closed too, and now on the aggregate: `isOwnedBy()` is false for a null owner and for a null candidate alike, so a session with no owner belongs to nobody rather than everybody, and the two readers can no longer disagree about it. Never echoed to clients — `ChunkingResponse::publicSessionData()` is an allowlist and omits it |

### `upload_token`

| | |
| :--- | :--- |
| **Enters as** | minted by the package at `/complete`; re-enters later from the consumer's own request |
| **Validated at** | `Rules\ValidUploadToken` → `StatefulChunkingService::resolveToken()` → MAC verification, JSON decode, then `StagedFileDTO::isValid()` (the `is_valid` flag **and** `expiresAt`) |
| **Normalised at** | n/a — opaque ciphertext |
| **First trusted at** | the consumer resolves `temp_path` and `disk` from the decrypted payload |
| **Status** | OK. `Crypt::encryptString` uses the host application's `app.cipher`, which the package does not pin: Laravel allows `aes-128-cbc`, `aes-256-cbc` (the default), `aes-128-gcm` and `aes-256-gcm`. The CBC variants are encrypt-then-MAC and the GCM ones are AEAD, so under any of them tampering with `temp_path` or `disk` fails closed and there is no padding oracle — but the key length is the app's choice, not ours. The TTL is enforced and deliberately shorter than the session's (7200s vs 6h), so a leaked token dies first. Never logged — `ChunkUploadController::complete()` explicitly `unset()`s it from the audit context |

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
