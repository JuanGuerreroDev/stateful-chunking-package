---
status: 'accepted'
date: 2026-09-08
decision-makers: 'Juan Manuel Guerrero Cañón (maintainer)'
---

# Adopt Architecture Decision Records

## Context and Problem Statement

This package has accumulated non-obvious architectural decisions — a hexagonal
layering with a framework-agnostic domain, a staged upload-token scheme, a
storage-amplification defense, and a deliberate PII / server-path exclusion
policy on HTTP responses. Until now the *why* behind those choices lived only in
commit messages, proposal documents under `docs/proposals/`, and the memory of
whoever made the change.

That is fragile. A future contributor — human or coding agent — who touches the
HTTP layer, the domain entities, or the security boundary has no durable place to
learn why the code is shaped the way it is, and risks silently reversing a
decision that was made on purpose (for example, re-adding `owner_id` to a
response "to be helpful"). How do we record architecture decisions so that they
are discoverable, respected, and implementable without asking follow-up
questions?

## Decision

Adopt lightweight Architecture Decision Records, stored in `docs/decisions/`,
following the conventions in that directory's `README.md`:

- Sequential, zero-padded, slug-suffixed filenames (`0001-<slug>.md`), referenced as `ADR-NNNN`.
- YAML front matter carrying `status` and `date`.
- ADRs are written as **executable specifications**: each records the context,
  the options weighed, the chosen outcome, an **Implementation Plan**, and
  **Verification** criteria specific enough to check.
- Accepted ADRs govern the area they describe. Contradicting an accepted ADR
  requires a new ADR that supersedes it — not a silent code change.

**Scope / non-goals**: this ADR only establishes the practice and its format. It
does not retroactively require an ADR for every past decision; records are
written going forward, or backfilled when a decision is revisited.

## Consequences

- Good, because the reasoning behind hard-to-reverse decisions becomes durable and discoverable next to the code.
- Good, because agents can consult accepted ADRs before editing and avoid reversing intentional choices.
- Bad, because it adds a small authoring step for genuinely architectural changes (mitigated: routine changes do not need an ADR).
- Neutral, because it introduces a new `docs/decisions/` directory alongside the existing `docs/proposals/` and `docs/security/` docs.

## Implementation Plan

- **Affected paths**: `docs/decisions/` (new): `README.md` index, this record, and subsequent ADRs.
- **Dependencies**: none (Markdown only; the `.claude/skills/adr-skill` tooling is optional and dev-only).
- **Patterns to follow**: the templates in `.claude/skills/adr-skill/assets/templates/` (simple for straightforward decisions, MADR when multiple options are weighed).
- **Patterns to avoid**: do not bury architectural reasoning in commit messages or long code comments when it governs how other code must be written — write or update an ADR instead.

### Verification

- [x] `docs/decisions/` exists with a `README.md` index.
- [x] The index lists every ADR with its status.
- [x] At least one substantive ADR (ADR-0002) demonstrates the executable-spec format.

## More Information

- Proposals that predate this practice live in `docs/proposals/`; security guides in `docs/security/`. ADRs may reference them as prior art.
- Revisit this decision if the number of ADRs grows large enough to need categorization (by layer or bounded context) — see the skill's guidance on categories.
