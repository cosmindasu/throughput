# ADR-007: Activity log written in-house, not `owen-it/laravel-auditing`

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Owner
- **Tags**: audit, observers, queues, sprint-5

## Context and problem statement

The `owen-it/laravel-auditing` package is historically the most widely used for this pattern in Laravel. The research from 2026-09-12 mentioned it, but explicitly flagged that **it had not been validated for Laravel 12 compatibility** during the research session.

The choice is between pinning it as a dependency on the strength of an assumption, and writing about half a day of code.

## Decision outcome

**In-house code.** Eloquent model observers (`created`, `updated`, `deleted`) on the relevant business models dispatch an event; a **queued listener** writes the row into `activity_log`, asynchronously.

Three reasons, in order of weight:

1. **We do not pin an unvalidated dependency.** If the package does not support Laravel 12, we find out in Sprint 5, not now.
2. **Asynchronous writing is a requirement**, not a preference — research §8 recommends it explicitly, and a package that writes synchronously inside the request thread would have to be worked around anyway.
3. **Consistency with ADR-001** — the project demonstrates construction, not configuration. A hand-written audit log is exactly the kind of code a technical reviewer reads carefully.

## Consequences

### Positive

- Full control over the shape of the diff and over the retention policy.
- No dependency on a third-party package's maintenance cadence for a compliance feature.

### Negative / trade-offs

- About half a day of extra code, and the responsibility for testing it.
- Features the package would have provided for free (auditing many-to-many relations, restoring a previous version) do not exist in the MVP. Not planned; they get added if the need appears.
