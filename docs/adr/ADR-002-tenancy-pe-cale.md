# ADR-002: Path-based multi-tenancy (workspace slug), not subdomain-based

- **Status**: Accepted — **amended by [[ADR-022]]** (the language segment is explicitly kept out of the URL, see the note below)
- **Date**: 2026-09-12
- **Deciders**: Tech Lead
- **Related**: [[ADR-003]] (the isolation mechanism), [[ADR-001]]
- **Tags**: multi-tenancy, dns, tls, routing, sprint-1

> **Amendment, 2026-09-20 — [[ADR-022]].** The decision below does not change. When the application became bilingual (EN + FR), a language segment in the URL (`/fr/{workspace}/...`) was also considered — and explicitly rejected, on the same reasoning as here: "the URL is decoration." Language is a per-user preference (`users.locale`), not a path segment. The note is added so that the argument below is not reopened without context.

## Context and problem statement

Throughput is multi-tenant: several organizations, fully isolated data, a workspace switcher in the interface. What remains to be chosen is **how the tenant is identified in the URL**: subdomain (`acme.throughput.dbg.ro`) or path (`throughput.dbg.ro/acme`).

The decision has to be made before the first deploy, because it determines the DNS records and the shape of the TLS certificate.

Check performed against the real DNS (2026-09-12): the `dbg.ro` domain has a `*.dbg.ro` wildcard pointing at `86.35.3.192/193` (shared hosting), and the 11 projects override it with explicit A records pointing at the VPS. The wildcard resolves **at two levels as well** — `acme.throughput.dbg.ro` already returns the parking IP.

## Decision drivers

- **Certificate reliability** — this is a portfolio demo; an expired certificate exactly when a prospective client is looking is the worst possible way to lose a contract.
- **Operational effort** — any recurring manual step will be missed one day.
- **Demonstrative value** — what actually convinces a buyer that the application really is multi-tenant.

## Considered options

### Option 1: Subdomain tenancy

- **Pro**: the "real SaaS" perception; clear visual isolation between organizations; precedent in Slack, Freshdesk.
- **Con**: requires an explicit `*.throughput.dbg.ro` A record (the existing wildcard leads to parking) **and** a wildcard TLS certificate. Let's Encrypt wildcards are issued exclusively through the DNS-01 challenge, not HTTP-01. DNS is with Romarg; without an API for automation, that means a manual renewal every 90 days.

### Option 2: Path tenancy, with a workspace switcher (CHOSEN)

- **Pro**: an ordinary certificate, issued and renewed automatically as on the other 11 projects. Zero recurring manual steps. Strong precedent: Linear, Notion, Vercel, Height.
- **Con**: the URL no longer carries the organization's identity; slightly less "SaaS" at first glance.

## Decision outcome

**Chosen: Option 2 — path tenancy.**

The tenant is resolved from the path segment (`/{workspace}/...`) after authentication, with a workspace switcher in the interface. A single certificate, on `throughput.dbg.ro`.

The underlying reasoning: **the demonstrative value does not come from the shape of the URL, but from what the buyer sees** — you switch workspace and all the data changes, with isolation enforced in the database ([[ADR-003]]). That is the demonstration; the URL is decoration.

If a client explicitly asks for subdomain tenancy, **a single** trial subdomain is added, with its own record and certificate, to demonstrate the mechanism — without a wildcard.

## Consequences

### Positive

- Fully automatic TLS issuance and renewal, identical to the rest of the portfolio.
- A single A record to maintain: `throughput` → VPS.
- No dependency on the API capabilities of Romarg's DNS.

### Negative / trade-offs

- Routes carry one extra segment; `route()` and Inertia's links have to propagate it consistently. Solved with a central helper plus a default in `URL::defaults()`, established in Sprint 1.
- If the product ever became real, moving to subdomains would require redirects. Accepted: it is a demo.
