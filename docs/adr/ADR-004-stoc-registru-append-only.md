# ADR-004: Stock as an append-only ledger, not as a mutable quantity

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Tech Lead
- **Related**: [[ADR-001]]
- **Tags**: inventory, data-model, audit, sprint-3

## Context and problem statement

The stock module has to answer two questions: "how many units do I have now" and "why that many". The second one is what builds trust in a demo.

The naive implementation keeps a `quantity` column that it increments and decrements. It answers the first question quickly and the second not at all.

## Decision drivers

- **Auditability** — "why does it show 7 units when I counted 9" is the question every warehouse operator has asked at least once. A system that cannot answer loses their trust.
- **Correctness under concurrency** — two simultaneous operations on the same column require locking; an append-only ledger has no write conflict.
- **Competence signal** — it is the distinction that shows most clearly between a beginner's implementation and a mature one, and the project exists precisely to demonstrate that difference.

## Considered options

### Option 1: A mutable `quantity` column

- **Pro**: trivial to implement; instant reads.
- **Con**: no history; impossible to reconcile; requires pessimistic locking on concurrent writes; a wrong correction is irreversible and invisible.

### Option 2: Append-only ledger + materialized `on_hand` (CHOSEN)

- **Pro**: every movement has a reason, a moment, an author and a reference to the document that produced it. Full reconciliation. Writes are inserts, so no conflict. Corrections are new movements, not deletions.
- **Con**: more code; `on_hand` has to be kept correct, otherwise there are two sources of truth.

## Decision outcome

**Chosen: Option 2.**

`stock_movements` is append-only: `variant_id`, `location_id`, `delta` (positive or negative), `reason` (`receipt` / `sale` / `adjustment` / `return` / `transfer`), `ref_type` + `ref_id` pointing at the source document, `created_by`, `created_at`. No `UPDATE`, no `DELETE`.

The available quantity is materialized in an `inventory_levels` table, updated in the same transaction as the movement insert. There is a reconciliation command that recomputes from the ledger and reports divergences — also used as a test: if the recomputation does not produce the same result, something wrote `on_hand` without writing the movement.

## Consequences

### Positive

- Every quantity on screen is explainable all the way back to the document that produced it.
- Reserving stock when an order is confirmed and releasing it on cancellation become natural (movements with distinct reasons).
- The "I shipped 500 orders, watch the stock move row by row" demo is only possible with this structure.

### Negative / trade-offs

- The ledger grows without bound. Irrelevant for a demo; for production it would require periodic aggregation over closed periods. Noted, not implemented.
- Two places to write on every movement (ledger + materialized level), mandatorily in the same transaction. Protected by the reconciliation command and by a dedicated test.
