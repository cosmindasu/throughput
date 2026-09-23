# ADR-014: Tenant context — a single gate, two session variables, a dedicated policy for `memberships`

- **Status**: Accepted — **the SQL form of the comparison in the RLS policies (point 2) is partially superseded by [[ADR-016]]**
- **Date**: 2026-09-12
- **Deciders**: Owner
- **Partially supersedes**: [[ADR-003]] — exclusively point 1 of its decision outcome, "The context is set with `SET LOCAL`, inside a transaction, not with `SET` on the connection." The intent of that sentence (transaction-scoped context, never connection-scoped) stands and is what this ADR implements; only the SQL form changes, to `select set_config('app.tenant_id', ?, true)`. The rest of [[ADR-003]] remains in force, untouched: two layers rather than one, the options weighed, and point 2 (no `BYPASSRLS` for the application role).
- **Related**: [[ADR-003]] (two-layer isolation), [[ADR-013]] (external calls in queues), [[ADR-002]]
- **Tags**: multi-tenancy, rls, postgresql, memberships, queues, sprint-1

## Context and problem statement

[[ADR-003]] requires every tenant-scoped table to have an RLS policy over `current_setting('app.tenant_id')`, and the context to be set with `SET LOCAL` inside a transaction. The plan implemented the rule literally and uniformly, on every table. Verification on a clean PostgreSQL 16 (2026-09-12, reproduced below) showed that the literal implementation has **three holes**, none of them visible by reading the code:

1. **`SET LOCAL` does not accept bound parameters.** `DB::statement('SET LOCAL app.tenant_id = ?', [$id])` — the form from the plan — performs `PDO::prepare()` + `execute()`, and PostgreSQL answers `SQLSTATE[42601]: syntax error at or near "$1"`. The mechanism the whole of ADR-003 rests on was not starting at all.
2. **`memberships` under RLS on `tenant_id` makes the second workspace undiscoverable.** The workspace switcher ([[ADR-002]], FR-TEN-01) and the "which workspace do I land in after login" decision both require a **cross-tenant** query on `memberships`, before any tenant is known. Measured: a user who is a member of two organizations sees **0** rows without a context and **1** with the first workspace's context. The only query that would find the second is already scoped to the first.
3. **Jobs and commands without a tenant have no mechanism.** `ApplyTenantContextToJob` assumes `$job->tenantId`, but the volume seed, `demo:reset`, the daily `sent → overdue` job, the report scheduler, the 36-month anonymization and the post-cancellation purge are all cross-tenant. Under a policy with only `USING`, PostgreSQL uses it **as `WITH CHECK` as well**, so without a context even an `INSERT` fails: `new row violates row-level security policy`.

On top of that, [[ADR-013]] moved external calls out of the HTTP request precisely because the middleware holds a transaction open — but the job middleware wrapped the whole of `handle()` in the same way, so the carrier call and the several-second Chromium ended up back inside a transaction, exactly what ADR-013 set out to prevent.

## Decision drivers

- **The mechanism has to be verified, not inferred.** Three successive bugs in the bootstrap script were delivered as "fixed" on the strength of reading the code. The same class of error struck here too.
- **A single gate.** The context is set in three places today (HTTP middleware, job middleware, commands). Three places = three ways to get it wrong.
- **Exceptions must be declared, not discovered.** A table that needs a different policy from the rest has to say why, somewhere someone will find by searching.

## Decision outcome

### 1. `set_config`, not `SET LOCAL`

```php
DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
```

The third argument, `true`, means transaction-scoped — the exact equivalent of `SET LOCAL`. Verified: it resets on commit, so a connection reused by PHP-FPM or by a Horizon worker does not inherit the previous request's tenant. The reverse was verified too: with `false` (the equivalent of a plain `SET`), the value **survives** the commit — the leak ADR-003 describes, reproduced in the lab.

### 2. Two session variables and a dedicated policy for `memberships`

`app.user_id` is set at authentication, before the workspace is known. `memberships` gets the only non-uniform policy in the application:

```sql
CREATE POLICY membership_visibility ON memberships
  USING (
        user_id::text   = current_setting('app.user_id',   true)
     OR tenant_id::text = current_setting('app.tenant_id', true)
  );
```

"My own membership rows, anywhere, **or** the current tenant's rows." Verified across all six cases:

| Situation | Result |
|---|---|
| `u1` authenticated, no workspace resolved | sees both workspaces — the switcher works |
| `u1` with context on `t1` | sees their own 2 + colleagues in `t1` — the Members screen works |
| `u2` with context on `t1` | does **not** see `u1`'s membership in `t2` — no leak |
| no context at all (public route, system job) | 0 rows — fails closed |
| `INSERT` of a membership in the current tenant | allowed (invitation) |
| `INSERT` of a membership in another tenant | rejected by RLS |

The only place in the application where bypassing the Eloquent global scope is legitimate is `Membership::forCurrentUserAcrossTenants()`, for the switcher. A test asserts that `withoutGlobalScope` appears nowhere else.

### 3. `TenantContext` — a single gate

One helper opens the transaction and sets the context; the HTTP middleware, the job middleware and the console commands all call it identically. The middleware order becomes `Authenticate → SetSessionContext → ResolveWorkspace`: the first opens the transaction and sets `app.user_id`, the second adds `app.tenant_id` **in the same transaction**, so there are no nested transactions. The name `ApplyTenantContext` from [[ADR-013]]'s implementation notes splits into these two.

### 4. Two families of jobs

- **Tenant jobs** — they receive a scalar `tenantId` (the rule from [[ADR-003]], addendum point 2) and use the context middleware.
- **System jobs** — they have no tenant. They **iterate tenants explicitly**, with one transaction and one context per tenant. Verified to be possible: switching context within the same transaction and on the same connection is valid.

The `BYPASSRLS` role stays reserved exclusively for `artisan migrate` and `demo:reset` — which do DDL, not data manipulation. The reason it is not granted more widely: "I'll put the job on the migration connection so I don't have to fight RLS" is the shortcut someone takes six months later, and it disables the second net precisely in the jobs that write in bulk.

The Stripe webhook is the only place where the tenant comes from an external payload: the route is public and context-free, `tenants` has no RLS, so the lookup by `stripe_id` works — but the processing job is a **tenant job**, with `tenantId` resolved by the controller. A `stripe_id` that maps to no tenant produces `webhook_events.status = failed` with a reason, not an uncontrolled exception.

### 5. Jobs with external I/O manage their own context

The context middleware is applied **per job**, not globally. Jobs that do I/O outside our own Postgres (`GenerateShippingLabelJob`, PDF generation, reports) do not use it — they call the helper twice, with the external call between the transactions:

```text
[short transaction] read the input data           → commit
external call / Chromium                           (no open transaction)
[short transaction] write the result               → commit
```

The cost is a window between the two transactions, acceptable because the jobs are idempotent on the resource id. The alternatives were rejected: a short transaction just for the context does not work (on commit the context is lost and the rest of the job sees zero rows), and `set_config(..., false)` demonstrably leaks and leaves the wrong tenant to the next job if the current one throws.

### 6. `after_commit` on the Redis queue

With a transaction open for the whole duration of the request, **every** `dispatch()` happens inside a transaction — the worker can pick the job up before the commit and not find the row. `'after_commit' => true` on the queue connection, in `config/queue.php`. Without it: intermittent failures that look random, exactly on the shipping label and on bulk operations, where the interface polls a row that does not exist yet.

## Consequences

### Positive

- The isolation mechanism actually starts. Before this verification, it did not.
- One place to read and to get wrong for the context, instead of three.
- The workspace switcher works without weakening RLS on the table that decides who has which role where.
- System jobs have a rule, not a per-job improvisation.

### Negative / trade-offs

- A second session variable to keep in mind, and one table with a policy different from the rest — both documented here precisely so they are not discovered through debugging.
- The middleware order becomes significant: `SetSessionContext` **must** run before `ResolveWorkspace`. A route test verifies the order.
- Jobs with external I/O have two transactions, hence a concurrency window. Accepted, since they are idempotent.

## Verification

Reproduced on `postgres:16-alpine` (PostgreSQL 16.14), a clean container, with real PDO (PHP 8.4, `pdo_pgsql`) for the Laravel code path — not through `psql`, which interpolates client-side and would have hidden the bug in point 1. The scenarios tested map 1:1 onto FR-TEST-01/02/03 from the specification and become the basis of the Phase 1 isolation suite.
