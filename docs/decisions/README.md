# Architecture Decision Records (ADR)

An Architecture Decision Record (ADR) captures an important architecture decision
along with its context and consequences. In this repository ADRs are treated as
**executable specifications**: a human approves the decision, and an agent (or a
future contributor) can implement it from the record alone, without tribal
knowledge.

## Conventions

- **Directory**: `docs/decisions/`
- **Naming**: zero-padded sequential number + slug — `0001-adopt-architecture-decision-records.md`. Reference an ADR as `ADR-0001`.
- **Status values**: `proposed`, `accepted`, `rejected`, `deprecated`, `superseded`

## Workflow

- Create a new ADR as `proposed`.
- Discuss and iterate.
- When the decision is committed: mark it `accepted` (or `rejected`).
- If replaced later: create a new ADR and mark the old one `superseded` with a link both ways.

## ADRs

| ADR | Title | Status |
| --- | ----- | ------ |
| [ADR-0001](0001-adopt-architecture-decision-records.md) | Adopt Architecture Decision Records | accepted |
| [ADR-0002](0002-adopt-immutable-response-envelope.md) | Adopt an immutable response envelope for the Chunking HTTP API | accepted |
| [ADR-0003](0003-normalise-identity-at-the-adapter-boundary.md) | Normalise caller and session identity at the adapter boundary | proposed |
| [ADR-0004](0004-staged-chunk-lifecycle-and-garbage-collection.md) | Collect abandoned staging chunks by event and by sweep, not from the state repository | proposed |
