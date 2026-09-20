# ADR-010: Two shipping carriers, selectable per tenant, plus a demo carrier

- **Status**: Accepted — **the scope escape valve was pulled on 2026-09-12** (see the note below)
- **Date**: 2026-09-12
- **Deciders**: Owner
- **Related**: [[ADR-001]] (hand-built components, not configuration)
- **Tags**: shipping, integrations, multi-tenancy, adapters, sprint-5

> **Valve pulled — EasyPost is out, 2026-09-12.** This ADR explicitly allowed the EasyPost adapter to be deferred "without touching the architecture". The trigger was not a lack of time but a finding at sign-up: **EasyPost gates access to API keys — test keys included — behind a monthly subscription.** A provider with a recurring fee, for the third tenant of a portfolio demo, is not justifiable.
>
> **What remains:** the `ShippingCarrier` interface, the contract test suite run identically against every implementation, the per-tenant settings screen with encrypted credentials, and **two** implementations — `demo` and `shippo`. The `provider` enum now holds only the implemented values (`shippo`, `demo`): a value without an adapter would be dead code that a reviewer spots immediately.
>
> **What is lost, explicitly:** the "two different external integrations, one interface" demonstration. What remains demonstrated is "the provider is configured **per tenant**, with its own credentials" — Cascade and Northgate both start on `shippo`, with separate rows in `tenant_carrier_settings`. The decision's central argument (per-tenant configurability over a stable interface) does not depend on the number of providers; the real cost of a third is now documented as **one enum value + one adapter + the same contract suite**, which is itself the proof that the abstraction holds.
>
> **A secondary gain, not a negligible one:** Phase 5 was flagged as overloaded by the audit (P2-002). It loses about a day of work, on exactly the tightest phase. The valve worked as intended.

## Context and problem statement

The specification (§11.5) defined a `ShippingCarrier` interface (`createLabel`, `void`, `trackingUrl`) and left the choice of provider open: EasyPost **or** Shippo. The initial recommendation was Shippo, on practical grounds — there is already a working Shippo integration in the older `demo.dbg.ro` project, so it would have been faster.

The owner rejected picking just one, with a better argument: **this is a demo, and prospective clients arrive with existing accounts.** Some have Shippo, others EasyPost. Showing both integrations is a stronger commercial claim than showing one.

## Decision drivers

- **Commercial argument** — "I can work with the carrier account you already have" is a sentence that closes objections. "I have integrated Shippo" is not.
- **Engineering argument** — an interface with a single implementation is an assumption, not an abstraction. You do not know whether `ShippingCarrier` is well designed until you implement it twice. The second implementation **validates** the design of the first.
- **Demonstrating per-tenant configuration** — if each workspace picks its own provider and supplies its own credentials, the demo shows one more SaaS capability for free: per-organization configuration with secrets stored encrypted.

## Decision outcome

**Both implementations, selectable per tenant, plus a third one for demo purposes.**

### Per-tenant configuration

A `tenant_carrier_settings` table: `tenant_id`, `provider` (enum: `shippo` | `easypost` | `demo`), credentials **encrypted at rest** with Laravel's encrypter, `is_active`. A settings screen visible to the Owner role.

Storing credentials encrypted is not a bureaucratic detail — it is one more thing a technical reviewer looks for and rarely finds in a demo.

### The third implementation: `DemoShippingCarrier`

**An addition beyond the original request, proposed for resilience.** It returns a plausible PDF label and a fake tracking number, with no external call.

The reason: with two real providers, the public demo depends on two external sandboxes. If one goes down on a Saturday, a buyer who clicks "Ship" sees an error — exactly the moment when you cannot afford one. The demo provider is the default for public tenants; the two real ones remain selectable and configured against their sandboxes.

### Configuration of the three seeded tenants

Every seeded tenant starts on a different provider, so that a visitor sees all three states **without configuring anything**:

| Tenant | Provider | What it demonstrates |
|---|---|---|
| 1 (default on entry) | `demo` | The full flow, with no external dependency |
| 2 | `shippo` (sandbox) | A real integration |
| 3 | `shippo` (sandbox, its own credentials) | **Per-tenant** configuration: same provider, separate row, separate credentials |

*(Row 3 called for `easypost` until 2026-09-12 — see the valve note at the top of the document. "All three states" becomes "both states": no external dependency, and a real integration.)*

## Consequences

### Positive

- The `ShippingCarrier` interface is validated by two real implementations, not assumed.
- The demo cannot break because an external sandbox is down.
- Two extra things are demonstrated: per-tenant configuration and encrypted credential storage.
- A direct sales argument for clients who already have a carrier account.

### Negative / trade-offs

- **About one extra day in Phase 5** — the second implementation, the settings screen, credential encryption. Phase 5 is already the heaviest; if the audit confirms it is overloaded, `easypost` can be deferred without touching the architecture, because the interface stays the same.
- Two sandbox accounts to maintain, with keys that can expire.
- Three code paths to test instead of one. Mitigated: the contract tests run the same suite against all three implementations — which is, again, something worth showing.
