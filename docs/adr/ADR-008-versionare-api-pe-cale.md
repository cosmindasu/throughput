# ADR-008: Public API versioned in the path (`/api/v1/...`)

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Owner
- **Tags**: api, versioning, openapi, sprint-5

## Context and problem statement

The public API has to be versioned. Two common forms: in the path (`/api/v1/orders`) or through content negotiation in a header (`Accept: application/vnd.throughput.v1+json`).

## Considered options

### Option 1: In the header

- **Pro**: considered "purer" — the same resource keeps the same URI regardless of version.
- **Con**: invisible. It cannot be tested by opening an address in a browser, it is harder to debug, and consumers get it wrong frequently (they omit the header and receive the default version without noticing).

### Option 2: In the path (CHOSEN)

- **Pro**: visible and verifiable with `curl` or straight in a browser; universally understood; documents itself naturally in OpenAPI.
- **Con**: the theoretical objection about resource identity.

## Decision outcome

**In the path.**

The deciding argument is specific to this project: it is a **demo**. A buyer who opens `/api/v1/orders` and sees valid JSON forms an impression on the spot. That same person will not build a content-negotiation request just to check whether the API exists.

REST purity is a cost paid by someone who is not looking. Visibility is a gain with every visitor.

The specification was already working on this premise (§18.3), so no changes are needed.

## Consequences

### Positive

- Zero friction when demonstrating; the OpenAPI documentation is directly navigable.
- Laravel's routing expresses it naturally through prefix groups.

### Negative / trade-offs

- With an eventual `v2`, routes are duplicated per prefix. Acceptable; it is the problem of any genuinely versioned API, and it is not on the horizon for a demo.
