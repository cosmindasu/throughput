# ADR-015: Laravel 13 and Inertia 3, not Laravel 12 and Inertia 2

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: project owner
- **Partially supersedes**: [[ADR-001]] — exclusively the framework and Inertia versions. The rest of ADR-001 (choosing Laravel + Inertia + React + PostgreSQL over Next.js, Filament or Livewire, and the reasons for that choice) remains in force, untouched.

## Context and problem statement

[[ADR-001]] was written on the morning of 2026-09-12 and pins the stack to **Laravel 12 + Inertia v2 + React 19 + PostgreSQL 16**. On the first actual scaffold run, the same day, `composer create-project laravel/laravel` installed **Laravel 13.31.0**, and the latest `@inertiajs/react` is **3.7.1** (`inertiajs/inertia-laravel` v3.3.4).

In other words: both moved a major version past what the documents assume, and the discrepancy appeared the minute the first line of code was written, not months later. It had to be settled before anything else — re-scaffolding costs zero now and grows more expensive with every phase.

The project convention (`docs/adr/README.md`) says that **an accepted ADR is never rewritten**: if the decision changes, a new one is written that supersedes it. Hence this document, instead of a silent edit in ADR-001.

## Decision drivers

1. **Compatibility cost on the already-planned stack** — if a package in the plan does not support Laravel 13, the discussion closes itself.
2. **The support window** of the version a *new* project starts on.
3. **What the secondary audience from `specs.md` §1.5 sees** — the technical recruiter / CTO doing a quick code review, who will run `laravel new` themselves as a point of comparison.
4. **The cost of keeping the documents in sync.**

## Considered options

### Option A — Laravel 13 + Inertia 3 (chosen)

I **measured** compatibility rather than assuming it: I resolved the plan's entire package set against both framework variants, with `composer update --dry-run`, the platform pinned to PHP 8.3 and `minimum-stability: stable`.

| Package | On `laravel/framework ^12.0` | On `^13.0` |
|---|---|---|
| `inertiajs/inertia-laravel` | v3.3.4 | v3.3.4 |
| `laravel/cashier` | v16.8.0 | v16.8.0 |
| `laravel/horizon` | v5.49.0 | v5.49.0 |
| `laravel/sanctum` | v4.3.3 | v4.3.3 |
| `spatie/laravel-permission` | 8.3.0 | 8.3.0 |
| `spatie/laravel-pdf` | 2.13.1 | 2.13.1 |
| `maatwebsite/excel` | 4.0.2 | 4.0.2 |
| `pestphp/pest` | v4.7.8 | v4.7.8 |
| **framework** | **v12.69.2** | **v13.31.0** |

**The resolution is identical across all eight packages.** The only difference between the two variants is the framework version. The compatibility cost of Laravel 13, on exactly the stack in `plan-implementare.md`, is **zero** — and that is a reproducible measurement, not an impression.

The rest of the stack from ADR-001 is confirmed unchanged: **React 19** (19.3.0), **Tailwind 4** (4.3.3), **PostgreSQL 16**.

### Option B — stay on Laravel 12, as in the documents

Zero sync work on the framework side and ADR-001 untouched. Rejected:

- Laravel 12 was released in February 2025. Under Laravel's published support policy (bug fixes ~18 months, security ~2 years), the **active bug-fix window closed in August 2026** — one month before the date of this decision. Only security support remains. Starting a *new* project there is a hard choice to defend in front of the reader from driver 3.
- The project's central argument to the buyer is "current, idiomatic code". A reviewer who runs `laravel new` and compares sees a major version behind immediately, and the explanation ("the plan was written for 12") is exactly the kind of answer this project exists in order not to give.
- It does not remove the sync work anyway: the Inertia package is at v3 in both variants, so the "Inertia v2" mentions had to be touched regardless of the framework. Option B shrinks the sync work, it does not eliminate it.

### Option C — Laravel 13 with Inertia 2

Rejected without serious testing: it would mean deliberately pinning a package a major version behind, with no measurable benefit, in a combination nobody runs. Extra complexity for nothing.

## Decision

**Laravel 13 + Inertia 3 + React 19 + Tailwind 4 + PostgreSQL 16.** The scaffold installed in Sprint 0 stays as it is; the documents get synced.

Immediate operational consequences:

1. `composer.json` pins `config.platform.php = 8.3` — the local PHP is 8.4, while the container runtime is 8.3 (`plan-implementare.md` §3). Without it, local resolution could pick packages that require 8.4 and would break in production.
2. The requirements that depend explicitly on features introduced in Inertia 2 — **FR-PERF-01** (deferred props) and **FR-PERF-02** (prefetch on hover), plus the polling used for bulk-operation progress and for shipping label state ([[ADR-013]]) — are **verified against the Inertia 3 API before being implemented**, not assumed to carry over. If any of them changed shape, it gets noted in the phase that builds it.
3. The "Laravel 12" and "Inertia v2" mentions in `specs.md`, `plan-implementare.md` and `stack-options.md` are updated, with a version note — not silently.

## Consequences

**Positive**

- The project starts on the current version of the framework, with active bug-fix support.
- The measured cost is zero on packages; the only work is textual.
- The discrepancy was caught on the first scaffold command, not in Phase 3, where it would have meant a rewrite.

**Negative / to be accepted**

- From this date on, the project documents carry a version-correction layer: a reader of ADR-001 has to get here as well. Mitigated by the supersession note in ADR-001 and by the row in the `docs/adr/README.md` index.
- Laravel 13 is recent, so the ecosystem of smaller packages (the ones not in the table above) may lag. It affects nothing in the planned MVP — everything planned was tested above.
- An ADR that partially supersedes another is harder to read than one that replaces it entirely. I preferred it anyway: ADR-001 contains the argument for the stack against four alternatives, which remains valid in full and has no reason to be rewritten so I can change two numbers.

## Links

- [[ADR-001]] — technical stack (partially superseded: the versions only)
- [[ADR-013]] — external calls leave the HTTP request (depends on Inertia's polling)
- `specs_si_design/stack-options.md` — the four stack options evaluated
