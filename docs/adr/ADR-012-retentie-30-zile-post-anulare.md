# ADR-012: A 30-day retention window after subscription cancellation

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Owner
- **Related**: [[ADR-005]], [[ADR-006]] (the subscription), [[ADR-007]] (audit)
- **Tags**: subscription, gdpr, retention, deletion, sprint-5

## Context and problem statement

Once a tenant's subscription is definitively cancelled — voluntarily or after repeated payment failure — their data has to disappear at some point. The question is **when**.

The research is explicit on one point that matters: **there is no correct term.** GDPR requires minimization, not a number. Every provider sets its policy contractually.

## Decision outcome

**30 days**, with deletion in two stages.

- On cancellation: `subscription_canceled_at` is set, access is blocked, the data is intact.
- Within the window: the tenant **can export** their data (§20.5) and **can reactivate** the subscription without redoing onboarding.
- On expiry: a scheduled purge job.

Why 30 and not 60 or 90: it is the value industry implementations converge on most often (research: observed range 30–90, clustering at 30 as the "accidental" recovery threshold). For a demo, what matters is that the window **exists** and that inside it you can export and reactivate — not its length.

**The specification states explicitly that this is a product decision, not a legal requirement.** A document that says "30 days in accordance with GDPR" shows that its author has not read the regulation.

## What is not deleted on purge

Invoices and audit log records tied to financial transactions are **anonymized**, not deleted — Art. 17(3)(b) exempts data necessary for legal obligations. The links to the natural person are severed; the document and the figures are kept.

The tax retention period **varies by jurisdiction**. No number is fixed in code: it is a parameter configurable per tenant. The research explicitly refuses to recommend a universal value, and rightly so — the target market is international.

## Consequences

### Positive

- A tenant who cancels by mistake, or because of an expired card, can recover their account.
- Export stays possible exactly when it is most likely to be requested.
- The anonymization/deletion distinction is written down, not discovered at the first deletion request.

### Negative / trade-offs

- Data occupies space for 30 days after the tenant has left. Irrelevant at demo scale.
- The figure is arbitrary by its nature. Documented as such — it changes through a new ADR, not through a silent edit.
