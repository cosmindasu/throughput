# ADR-011: Deactivating a member is not blocked by the records they own

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Owner
- **Related**: [[ADR-007]] (activity log — history requires intact references)
- **Tags**: multi-tenancy, security, rbac, sprint-2

## Context and problem statement

When a member leaves a workspace, the records they own — accounts, deals, orders — stay behind. The question is what happens to them and, above all, **whether the departure can be blocked until someone takes them over**.

Version v1.3 of the specification had adopted **mandatory full reassignment**: removal was blocked until all 62 records in the example had been reassigned. The research from the same day argued explicitly against that variant, and the argument is decisive and had not been weighed:

> **Blocking deactivation blocks access revocation.**

If someone leaves in conflict, access has to be cut immediately. A policy that first demands the reassignment of thousands of historical records turns a security action into a housekeeping chore — and, in practice, delays revocation by days.

## Decision drivers

- **Access revocation is not negotiated against ergonomics.** It is the only action in the whole module with immediate security consequences.
- **Nothing may disappear silently.** A record without a visible owner is a record nobody looks at again.
- **History stays auditable** ([[ADR-007]]) — so references to the departed user cannot be broken.

## Considered options

The research compared four real products and found **three distinct patterns, with no consensus**:

| Product | Pattern |
|---|---|
| HubSpot | Placeholder, no blocking — the owner becomes `Deactivated/Removed (email)` |
| Salesforce | Manual reassignment first; no native automation (it is an open *Idea* on their portal) |
| Jira | An unassigned queue — `ASSIGNEE = NULL` is a valid system state, not an error |
| Zoho CRM | Blocking, with a mandatory transfer step |

## Decision outcome

**A hybrid, with deactivation never permanently blocked.**

1. **Deactivation is immediate.** Access is revoked now. `memberships.status = deactivated`, never a physical `DELETE` — the history in `activity_log` requires the reference to stay intact.
2. **A placeholder on historical references** — "Jane Doe (deactivated)", not an empty name and not an error.
3. **An "Unassigned" view per tenant**, populated automatically with the **open** records of deactivated members. The manager reassigns from there, at their own pace. Nothing is lost, nothing blocks.
4. **An extra confirmation for the critical subset** — active orders and open deals. Deactivation requires an explicit confirmation, with the option to reassign on the spot. **The Owner can choose "Deactivate anyway"**, and the records move into the "Unassigned" view.

Point 4 is the compromise: it keeps the rigor visible (you are warned that you are leaving 12 open deals without an owner) without reintroducing the block that point 1 rules out.

**An exception with strong consensus: the last Owner.** Slack, ClickUp, Webflow and Figma all block the removal of a workspace's last Owner. Here blocking is correct — there is no "later" for a workspace without an owner. Transferring ownership is a separate action, a precondition.

**Deliberately out of scope:** the case where the last Owner has already left without transferring. Webflow documents it as a support-mediated flow, not self-service. We do the same.

## Consequences

### Positive

- Access revocation stays instantaneous, no matter how many records someone owns.
- The "Unassigned" view is a better demonstration than blocking: *"nothing is lost when someone leaves the team"*.
- A single code path for deactivation, with a confirmation on top — not two parallel policies.

### Negative / trade-offs

- Records can stay unassigned indefinitely if nobody looks at the "Unassigned" view. Mitigation: a notification to the Owner on deactivation, plus a permanent count badge on the view.
- The demonstrative effect of a hard block is lost. Accepted — the "Unassigned" view is just as visible and more honest operationally.

## History

This decision **changes** what the specification said in v1.3 (§6.4.1, BR-TEN-03, US-TEN-03), where full reassignment was mandatory and blocking. That variant had been introduced at my own instruction, against the explicit recommendation in `docs/research/best-practices-goluri-2026-09-12.md` §3.2. The specification is aligned with this ADR in v1.6.
