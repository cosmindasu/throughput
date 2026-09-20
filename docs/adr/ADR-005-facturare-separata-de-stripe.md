# ADR-005: Customer invoicing is separate from the Stripe subscription

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Owner
- **Related**: [[ADR-006]] (Cashier for the subscription)
- **Tags**: invoicing, payments, domain, sprint-4

## Context and problem statement

"Invoicing" means two different things in Throughput, and mixing them would be simpler to build:

1. The invoices **the tenant issues to its own customers** — receivables on credit terms.
2. The subscription **the tenant pays to Throughput**.

The obvious temptation is "Stripe everywhere": card checkout for customer invoices too. It would reduce the code and reuse the same integration.

## Decision outcome

**The two flows stay separate.** Customer invoices are internal receivables (`credit_terms`, `due_date`, `balance_due`), with no card processor, reconciled manually. Stripe appears only for the tenant's subscription.

The domain reason: a wholesale distributor does not take card payments on every order — they work on net 30 and get paid by bank transfer. A demo that suggests otherwise shows that its author has never worked in the field.

The portfolio reason, just as important: **every portfolio on the freelancing platforms has a Stripe checkout.** Almost none shows an understanding of credit terms. The rare signal is the second one.

## Consequences

### Positive

- The demo demonstrates an understanding of the domain, not just the integration of an SDK.
- The `invoices` model stays clean: no processor states mixed in with receivable states.

### Negative / trade-offs

- The demo will **not** contain a "the customer pays by card" screen. Consciously accepted.
- Card payment of customer invoices (Payment Link) remains listed as a Phase 2 extension in §3.3 — it can be added without changing the model.
