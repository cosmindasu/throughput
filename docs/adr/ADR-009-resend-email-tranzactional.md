# ADR-009: Resend as the transactional email provider

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Owner
- **Related**: §22.3 of the specification (intercepting emails in the demo)
- **Tags**: email, reports, notifications, sprint-4

## Context and problem statement

The application sends email: scheduled reports with attachments, workspace invitations, notifications, password resets. It needs a provider.

The note in the specification (§16) framed the problem as "own queue vs external service" for report delivery. The framing was misleading: **there is no reasonable external service** for "run this query on Monday at 8 and email me the CSV", and the research confirms there is no canonical Laravel package for report scheduling.

The mechanism therefore decides itself — Laravel Scheduler → job → `Mailable` with an attachment. The real question, hidden underneath, was **who transports the email**.

## Decision outcome

**Resend.**

- Minimal configuration and a modern API; a Laravel driver is available.
- A free tier sufficient for demo volume (where, on top of that, emails to addresses outside the allow-list are intercepted anyway — §22.3).
- The serious alternative is Postmark, with a better deliverability reputation for real volume. Irrelevant here: the volume is negligible and the recipients are controlled.

**To verify before implementation:** the 2026 free-tier terms. The decision is made on configuration characteristics, not on pricing figures we have not confirmed.

## Consequences

### Positive

- The reporting mechanism stays entirely in the stack, with no SaaS dependency for scheduling — important on a VPS with a tight memory budget.
- Switching provider is an environment variable: Laravel abstracts the transport.

### Negative / trade-offs

- One more external account and one more set of keys to manage.
- If volume ever became real, the deliverability reputation would have to be re-evaluated. Not a concern for a demo.
