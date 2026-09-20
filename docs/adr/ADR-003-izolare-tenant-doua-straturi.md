# ADR-003: Tenant isolation in two layers — Eloquent global scope + Row-Level Security

- **Status**: Accepted — **point 1 of the decision outcome (the `SET LOCAL` form) is superseded by [[ADR-014]]**
- **Date**: 2026-09-12
- **Deciders**: Tech Lead
- **Related**: [[ADR-001]] (choosing PostgreSQL), [[ADR-002]] (identifying the tenant), [[ADR-014]] (the form that actually works, and two more holes in the literal implementation), [[ADR-016]] (where the cast goes in the policy)
- **Tags**: multi-tenancy, security, postgresql, rls, eloquent, sprint-1

> **Correction, recorded 2026-09-20 (read together with [[ADR-014]]).** Point 1 below says the context is set with `SET LOCAL app.tenant_id = ?` inside a transaction. **That form has never worked and was never in the code.** `SET` does not accept bound parameters, and `DB::statement()` prepares and executes, so PostgreSQL rejects it with `SQLSTATE[42601]`. The form actually in use is `select set_config('app.tenant_id', ?, true)` — a function call, so parameters bind normally, with the third argument scoping the setting to the current transaction. That third argument carries the entire safety property this point was written to describe: with `false`, the context survives the commit and the next request on the same pooled connection inherits it.
>
> [[ADR-014]] found this on a clean PostgreSQL 16 the same day, along with two further holes in the literal implementation (a uniform policy makes `memberships` undiscoverable; the application role must not hold `BYPASSRLS`), and is the decision in force. The correction was recorded here only in Phase 6 — until then a reader of this document had no way to know point 1 was wrong, which is the failure this note exists to close.
>
> **Everything else remains fully in force**: two layers rather than one, the options weighed, the addendum below, and point 2 (no `BYPASSRLS` for the application role). Only the SQL form of one sentence changes; the reasoning is not rewritten to correct a function call.

## Context and problem statement

In a multi-tenant application, a data leak between organizations is the one class of defect that has no degrees: it is either absent or fatal. A client who sees another client's orders ends the relationship and, depending on the jurisdiction, reports the incident.

The usual mechanism in Laravel is a **global scope** on every model, which automatically adds `WHERE tenant_id = ?`. It works, but it has well-known blind spots: `DB::table()` queries that bypass Eloquent, queued jobs that run without the request context, console commands, seeders, and anywhere someone writes `withoutGlobalScopes()` without understanding what it does.

## Decision drivers

- **The consequence of one slip is out of all proportion** to the cost of a second safety net.
- **Demonstrative value** — to a client's technical reviewer, isolation enforced in the database is a strong signal of maturity. From the portfolio's point of view, it is the best impression-to-effort ratio in the whole project.
- **Code changes, the database does not** — a new developer who adds a raw query must not be able to produce a leak.

## Considered options

### Option 1: Eloquent global scope only

- **Pro**: simple, idiomatic Laravel, zero database configuration.
- **Con**: a single net, with known holes (raw query builder, jobs, console). Nothing stops a leak once someone bypasses Eloquent.

### Option 2: PostgreSQL Row-Level Security only

- **Pro**: impossible to bypass from code; a guarantee at the database level.
- **Con**: opaque error messages during development (rows simply "do not exist"); it requires discipline in setting the context on every connection; tests become harder to read.

### Option 3: Both layers (CHOSEN)

- **Pro**: the scope gives intelligible errors and idiomatic code in 99% of cases; RLS catches the rest, including what has not been written yet. Each layer covers the other's weakness.
- **Con**: two mechanisms to understand and keep in sync; context to set correctly on the connection.

## Decision outcome

**Chosen: Option 3.**

Every table with tenant data carries `tenant_id`. Eloquent models have a global scope; on top of that, PostgreSQL has `ENABLE ROW LEVEL SECURITY` with policies over `current_setting('app.tenant_id')`.

Two implementation details decide whether this works at all:

1. **The context is set with `SET LOCAL`, inside a transaction, not with `SET` on the connection.** Connections are reused between requests and between jobs; a persistent `SET` would leave one tenant's context active for the next request — exactly the leak we are preventing.
2. **The application role does not have `BYPASSRLS`.** Migrations and maintenance commands run under a separate role that does. Otherwise the policies are decorative.

The tests cover both layers explicitly: one test that verifies the scope and one that bypasses Eloquent (`DB::table()`) and confirms that RLS blocks access.

## Addendum 2026-09-12 — details confirmed by research

Added the same day as the decision, after the report in `docs/research/best-practices-throughput.md`. **The decision does not change**; the implementation notes are extended, because the research surfaced four points we did not have.

1. **A composite index is mandatory.** `tenant_id` must be the **leading column** in any index used by filtered queries — `(tenant_id, created_at)`, not `(created_at)`. Otherwise RLS can be orders of magnitude slower. This applies to every high-volume tenant-scoped table.

2. **Jobs do not receive tenant-scoped Eloquent models in their payload.** On deserialization, `SerializesModels::restoreModel()` re-fetches the model from the database **before** any tenancy bootstrapper restores the context — so the query runs without the correct scope or fails silently. The rule: serialize `tenant_id` explicitly, and the first line of `handle()` re-binds the tenant in the container, ahead of any query.

3. **Broadcast channels are prefixed with the tenant** (`tenant.{id}.orders`), otherwise authorization can leak across tenants.

4. **Tests with `Queue::fake()` or the `sync` driver cannot see a leak coming from jobs.** The suite needs at least one test on a real queue driver, running a real job. Plus one isolation test per tenant-scoped model: create two tenants, assert that the second does not see the first one's data.

**Honesty note from the research:** there is no firm consensus in the sources found that RLS is mandatory at the scale of a single-database project of medium complexity — part of the literature treats it as a "nice to have, enterprise-grade" feature. Our decision keeps it, but the main reason remains the one in the drivers section: the impression-to-effort ratio for a portfolio demo, plus the fact that it is a second net where a slip is fatal. We do not claim it is the industry consensus.

**Security relevance:** the "I read another tenant's order by incrementing an ID" scenario is **OWASP API1:2023 — Broken Object Level Authorization**, the number one API risk. RLS covers it at the database level; the ownership check on every endpoint that receives an ID remains mandatory at the application level too.

## Consequences

### Positive

- A leak requires both layers to fail at the same time.
- A visible technical argument in a demo, at a low implementation cost.
- Queued jobs and console commands are as protected as HTTP requests.

### Negative / trade-offs

- In development, an unset context makes rows look nonexistent. Mitigated with a guard that throws explicitly when `app.tenant_id` is missing in a context that ought to have it.
- Migrations require a separate role, so one more connection to configure.
- The connection pool has to be understood up front, not afterwards. Documented in `plan-implementare.md`, Sprint 1.
