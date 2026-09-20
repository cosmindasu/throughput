# ADR-001: Technical stack — Laravel 12 + Inertia v2 + React 19 + PostgreSQL 16

- **Status**: Accepted — **the versions are partially superseded by [[ADR-015]]**
- **Date**: 2026-09-12
- **Deciders**: Tech Lead
- **Related**: [[ADR-003]] (RLS depends on choosing PostgreSQL), [[ADR-015]] (the versions actually in use)
- **Tags**: stack, laravel, inertia, react, postgresql, sprint-0

> **Version correction, 2026-09-12 (read together with [[ADR-015]]).** On the first scaffold run, `composer create-project laravel/laravel` installed **Laravel 13**, and the Inertia package is at **v3**. The stack actually in use is therefore **Laravel 13 + Inertia 3 + React 19 + Tailwind 4 + PostgreSQL 16**. [[ADR-015]] contains the measurement showing that the project's entire package set resolves identically on Laravel 12 and 13, so the change cost nothing in compatibility.
>
> **The rest of the document remains fully in force** — choosing Laravel + Inertia + React + PostgreSQL over Next.js, Filament and Livewire, with the four options evaluated, is unaffected by versions. I did not rewrite the reasoning in order to change two numbers.

## Context and problem statement

Throughput is the 12th project in the portfolio and the first that is **not** a brochure site for a small business. Its job is to demonstrate "internal tooling" competence — business applications used daily — to international buyers on freelancing platforms.

The stack choice is constrained by three things at once: what demonstrates the most, what fits on the existing infrastructure, and what the market asks for.

The current portfolio already contains **6 Next.js projects** and **4 that use Filament** as an admin panel.

Infrastructure: a VPS shared with the other 11 projects, 72 containers, in the process of being migrated to 10 GB of RAM. The 2026-09-08 measurement showed 11 OOM kills, 10 of them on `next-server` processes.

## Decision drivers

- **Portfolio differentiation** — a 7th Next.js project or a 5th Filament one demonstrates nothing new to a buyer who has already seen the others.
- **Memory footprint** — the project joins a machine with a history of memory pressure.
- **Market demand** — "Laravel + React internal tool" is a combination with steady demand on freelancing platforms.
- **The ability to demonstrate a hand-built UI** — not just the configuration of a generated panel.

## Considered options

### Option 1: Next.js 15 full-stack

- **Pro**: consistent with most of the portfolio; a single language; native React ecosystem.
- **Con**: a Node process that runs permanently and holds on to its heap even when idle — exactly the profile that produced the 10 measured OOM kills. And it demonstrates nothing that the other 6 do not already demonstrate.

### Option 2: Laravel 12 + Filament 4

- **Pro**: the fastest route to a complete CRUD; RBAC and resources out of the box.
- **Con**: Filament is recognized instantly. The buyer's conclusion becomes "he can configure Filament", which is a far smaller claim than "he can build the interface". It is already in 4 projects in the portfolio.

### Option 3: Laravel 12 + Inertia v2 + React 19 + TypeScript + PostgreSQL 16 (CHOSEN)

- **Pro**: PHP-FPM on `ondemand` drops to near zero between requests — the difference between fitting and not fitting on the current machine. Queues, scheduler and broadcasting included (needed for scheduled reports and bulk operations). A hand-built React interface, so it demonstrates construction, not configuration. A combination in high demand on the market.
- **Con**: two languages in the same project; Inertia demands discipline at the props/controller boundary so that it does not turn into a disguised API.

## Decision outcome

**Chosen: Option 3.**

Laravel 12 (PHP 8.3) + Inertia v2 + React 19 + strict TypeScript + PostgreSQL 16 + Redis + Horizon. Tailwind 4 with hand-built components, no generated panel library. Playwright for E2E.

**PostgreSQL, not MySQL**, for one specific reason: Row-Level Security (see [[ADR-003]]). Tenant isolation enforced in the database, not only in code, is the detail that reassures a client's technical reviewer.

## Consequences

### Positive

- Memory footprint estimated at 250–400 MB at peak, against the ~780 MB measured on the existing Next containers.
- The portfolio gains a second visible category, not yet another variation on the first.
- The queues and the scheduler needed for scheduled reports and bulk operations come out of the box.

### Negative / trade-offs

- Two languages to maintain (accepted: it is also a competence argument).
- Without Filament, the basic CRUD takes longer to write (accepted: that is precisely what is being demonstrated).
- Inertia demands clear conventions for props; without them, controllers become hard to read. They are established in Sprint 0.
