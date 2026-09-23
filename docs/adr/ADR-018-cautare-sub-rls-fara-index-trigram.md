# ADR-018: Global search under RLS filters over the tenant's rows, without GIN trigram indexes

- **Status**: Accepted
- **Date**: 2026-09-13
- **Deciders**: project owner
- **Related**: [[ADR-003]], [[ADR-016]]

## Context and problem statement

Global search (FR-SEARCH-01/02) uses `pg_trgm` directly in PostgreSQL: the `%` operator for typo tolerance and `ILIKE` for substrings. BR-SEARCH-01 required GIN trigram indexes on `accounts.name`, on `contacts.(first_name || ' ' || last_name)` and on `deals.title`, and the Phase 2 search package created them.

On verification with `db:explain-critical`, the contacts query fell back to a `Seq Scan`. The code review identified the cause, and the measurement below confirmed it: **on a table with RLS, PostgreSQL cannot use an index for a condition that contains non-LEAKPROOF functions.** The RLS policy acts as a security barrier. Any function that could leak information about invisible rows (through errors or timing) is evaluated AFTER the policy's condition, never as an `Index Cond`. Neither `similarity` / `similarity_op` (the `%` operator) nor `texticlike` (`ILIKE`) is LEAKPROOF.

This is not a question of volume. The indexes do not become usable with more data; only the filter becomes more expensive.

## Decision drivers

- **Measured, not inferred** — the same rule as in [[ADR-016]].
- **Isolation stays untouched** ([[ADR-003]]): no relaxation of the database's security barrier.
- **Memory and write budget** (`.ai/rules/project.md`): an index that cannot be used costs on every write, with no benefit whatsoever.
- **The real threshold is p95 < 200 ms on simple reads** (specs §20.1), not "it has an index".

## The measurement

Environment: PostgreSQL 16.14, the dev database after `demo:reset` (Marlin: 4,000 accounts, ~5,200 contacts out of 10,385 in total, 2,200 deals), the GIN indexes still present. The same query as in `GlobalSearchService`, with the tenant filter the global scope adds.

```text
proname       | proleakproof
similarity    | f
similarity_op | f
texticlike    | f
```

| Table | Without RLS (superuser) | Under RLS (`throughput_app`, Marlin context) |
|---|---|---|
| `accounts` (`name % 'fastners' OR name ILIKE …`) | BitmapAnd: the tenant index ∧ `accounts_name_trgm` (Index Cond on `%` and `~~*`), 8.2 ms | Bitmap Index Scan on the tenant index; `%`/`ILIKE` only as a **Filter**, 3,842 rows removed, 7.6 ms |
| `contacts` (full name) | Bitmap Index Scan on `contacts_name_trgm`, 0.6 ms | **Seq Scan**, filter on tenant + trigram, 10,365 rows removed, 22.5 ms |
| `deals` (`title`) | — | Bitmap Index Scan on the tenant index; trigram as a Filter, 1,923 rows removed, 6.4 ms |

The only difference between the columns is RLS. Without it, the GIN index enters the plan; with it, it is absent from the plan on all three tables.

## Considered options

1. **Mark the `pg_trgm` functions LEAKPROOF** (in the bootstrap script, as superuser). The GIN index becomes usable, but this is a deliberate relaxation of the security barrier. `ILIKE` would have to go (`texticlike` is a core function, used by every `ILIKE` in the application), and search would be left with `%` / word similarity only. Rejected: the gain (from ~20 ms to ~1 ms) does not matter at this product's scale, while the risk is permanent.
2. **Accept the filter, keep the indexes** in case of an eventual move to option 1. They cost in writes and memory with no benefit today. Rejected.
3. **Accept the filter, drop the indexes.** **Chosen.**

## Decision

- Global search stays on `%` (default threshold 0.3) + `ILIKE`, evaluated over the current tenant's rows. Access to those rows goes through the composite index with `tenant_id` in the first position ([[ADR-003]] addendum) or through a Seq Scan, at the planner's discretion.
- The migration with the GIN trigram indexes is deleted. Nothing had been pushed or deployed to production.
- `db:explain-critical` treats the search entries separately: the Seq Scan is explicitly accepted, and in its place an execution-time budget applies (200 ms by default, the §20.1 threshold). The remaining critical queries keep the strict rule.
- BR-SEARCH-01 (specs.md) and plan §8/§14 are corrected, with an entry in the Change Log.

## Consequences

### Positive

- No relaxation of database security. The two-layer isolation remains exactly the one in [[ADR-003]].
- Less write cost and less memory (3 fewer GIN indexes, plus the ones prepared for products).
- `db:explain-critical` no longer fails on a correct plan and no longer demands an index that cannot be used.

### Negative / trade-offs

- The cost of search grows linearly with the tenant's row count: ~20 ms over ~5,000 contacts today. A tenant on the order of hundreds of thousands of rows would require a re-evaluation — option 1 or a dedicated engine (FR-SEARCH-02 puts that threshold at "tens of millions of rows").
- Searching contacts does a Seq Scan on the showcase tenant. Correct as a plan, but visible in `EXPLAIN`; which is why the `db:explain-critical` rule is explicitly different for search.

## History

- 2026-09-13 — created. The owner chose option 3, on the strength of the measurement above and the code review of the search package.
