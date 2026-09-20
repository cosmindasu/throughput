# ADR-016: RLS policies cast the setting, not the column

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: project owner
- **Partially supersedes**: [[ADR-014]] — exclusively the SQL form of the comparison in the policies (the block in point 2) and the uniform `{$column}::text = current_setting(...)` form from plan §7.2. The rest of ADR-014 remains in force, untouched: `set_config` instead of `SET LOCAL`, the two session variables, the dedicated `memberships` policy as a decision, the single `TenantContext` gate, the two families of jobs, and `after_commit`.
- **Related**: [[ADR-003]], [[ADR-014]]

## Context and problem statement

The plan (§7.2) and [[ADR-014]] point 2 prescribe the RLS comparison with the cast on the column: `tenant_id::text = current_setting('app.tenant_id', true)`, and the same in the `memberships` policy, `user_id::text = current_setting('app.user_id', true)`. The `tenant_id` and `user_id` columns are `character(26)` (ULID). The `orders` table has the composite index `orders_tenant_id_status_created_at_index` on `(tenant_id, status, created_at)`, exactly the rule from the [[ADR-003]] addendum ("`tenant_id` as leading column").

Putting the cast on the column **wraps the column**: `(tenant_id)::text` is no longer the expression the btree index recognizes, so the planner cannot use the index for the policy's condition. Isolation stays correct — no row from another tenant escapes — so **no functional test saw the difference**. The Eloquent global scope adds `tenant_id = ?` with no cast at all, so ordinary requests through the models stayed fast; the cost fell exactly on the paths RLS exists for as a second net ([[ADR-003]]): raw SQL, aggregates, jobs.

Discovered by `db:explain-critical`, at the end of Phase 1 — that is, by reading the query plan, not by reading the code. The trap was not in the index definition (that one was correct, per the [[ADR-003]] addendum), but in the shape of the expression inside the policy.

## Decision drivers

- **Verified with `EXPLAIN`, not inferred from reading the code** — the same lesson as in [[ADR-014]]: a mechanism that "looks right" in code can be wrong at execution.
- **The fix does not weaken the isolation** already measured and tested in [[ADR-014]] — it changes only the form of the comparison, not which rows are visible.
- **A single generated form**, not hand-written comparisons per migration/policy — the source of the original error was precisely a uniform rule applied by hand, which is why the correction has to be automated, not repeated.
- **Zero schema cost** — no additional expression indexes, which would double the write cost and the memory occupied.

## Considered options

1. **Cast on the column + expression indexes** (`(tenant_id::text, …)`): doubles every existing composite index, with a cost in writes and memory on a VPS with a 250–400 MB budget. Rejected.
2. **No explicit cast** (`tenant_id = current_setting(...)`): measured — PostgreSQL resolves the `bpchar = text` comparison by putting the cast on the column itself, so the behaviour is identical to the cast-on-column variant. Rejected.
3. **Cast on the setting, `::bpchar`, generated from a single helper** (`EnablesRowLevelSecurity::matchesSetting()`), with no hand-written comparisons. **Chosen.**
4. **Changing the key type** (e.g. to `uuid`): the schema changes on every table, and the cast does not disappear — it only moves (`::uuid`). Rejected.

## The measurement

Environment: the `postgres:16-alpine` container, **PostgreSQL 16.14**, the dev database after `demo:reset` (3 tenants, 50,000 orders in total, tenant Marlin holding 30,000), role `throughput_app` (without `BYPASSRLS`), context set to Marlin. The query is the one from `db:explain-critical` for FR-ORD-02:

```sql
select * from orders where status = 'confirmed' order by created_at desc limit 50;
```

Each form was applied with `ALTER POLICY` inside a transaction aborted with `ROLLBACK`, so the real policy was never modified.

| Form | Plan | Rows discarded by the filter | Execution |
|---|---|---|---|
| A. `tenant_id::text = current_setting(...)` (plan §7.2 / ADR-014) | `Seq Scan on orders` + top-N sort | 45,500 | 19.2 ms (first run) |
| B. `tenant_id = current_setting(...)`, no explicit cast | identical to A: PostgreSQL resolves `bpchar = text` by putting the cast on the column, and the expression is deparsed as `(tenant_id)::text = current_setting(...)` too | 45,500 | 7.8 ms |
| C. `tenant_id = current_setting(...)::bpchar` (the code) | `Index Scan Backward using orders_tenant_id_status_created_at_index`, Index Cond on `tenant_id` and `status` | 0 | 0.107 ms |

**Honesty note:** at 50,000 rows the absolute milliseconds are small, and the times for A and B vary between runs (cache). The proof is not the stopwatch, it is **the shape of the plan**: `Seq Scan` grows with the whole table, that is, with all tenants, whereas `Index Scan` with a `LIMIT` does not.

## Fails closed — verified with form C, as `throughput_app`

- missing context (`current_setting` = `NULL`) → 0 rows
- setting `''` → 0 rows
- the correct tenant → 30,000 rows
- **after `COMMIT`, on the same session, `current_setting('app.tenant_id', true)` returns `''`, not `NULL`** → 0 rows. [[ADR-014]] and the plan say "`NULL` when not set"; on a reused connection (PHP-FPM, worker), the real case is `''`. The outcome is the same (0 rows), but it is worth recording as a verified fact.

## Decision

Every RLS policy compares the **uncast** column against the cast setting:

```sql
column = current_setting('app.x', true)::bpchar
```

Generated exclusively through `EnablesRowLevelSecurity::matchesSetting()` — no hand-written comparisons. `bpchar`, not `character(26)`: the btree index uses the `bpchar = bpchar` operator, and the declared length plays no part in operator selection.

The `memberships` policy from [[ADR-014]] point 2 becomes:

```sql
CREATE POLICY membership_visibility ON memberships
  USING (
        user_id   = current_setting('app.user_id',   true)::bpchar
     OR tenant_id = current_setting('app.tenant_id', true)::bpchar
  );
```

## Consequences

### Positive

- The RLS path uses the composite indexes: `db:explain-critical` is green on all 10 critical queries, and the orders list responds in 0.8 ms.
- The regression is caught by `IsolationTest::test_no_policy_casts_the_indexed_column`, which reads `pg_policies` and fails if any `qual` contains `)::text = current_setting`. It catches form B as well, since PostgreSQL stores it deparsed identically to A.
- The Pest suite: 63/63, including the six `memberships` visibility cases from [[ADR-014]] point 2, which remain valid under the new form.

### Negative / trade-offs

- The helper assumes `character(n)` keys (the project's ULID convention). A column of another type requires re-verification with `EXPLAIN`, not copying.
- The `memberships` migration and the trait were edited in place, without a corrective migration. This is valid because `demo:reset` runs `migrate:fresh` daily (FR-DEMO-03), so every environment gets the new form at its first reset. A production database with persistent data would have required a migration with `ALTER POLICY`.

## Verification

Reproduced on `postgres:16-alpine` (PostgreSQL 16.14), a dev database fully seeded after `demo:reset` (3 tenants, 50,000 orders), as role `throughput_app` (without `BYPASSRLS`). Method: each form of the comparison applied with `ALTER POLICY` inside a transaction aborted with `ROLLBACK`, so the real policy was never touched during the measurement. The results are the ones in the table above, plus the "fails closed" cases verified separately on the final form.

Form C is the one in the code, committed in `8da5f90` (`fix(rls): cast pe setare, nu pe coloană`).
