# ADR-025: Permission checks without a model are memoised per User instance

- **Status**: Accepted
- **Date**: 2026-09-29
- **Deciders**: project owner
- **Related**: [[ADR-014]] (the tenant context whose team id this memo must be keyed on), [[ADR-011]] (the ownership narrowing that must stay per-row), [[ADR-013]] (the "measure, don't assume" precedent for a performance rule), [[ADR-021]] (precedent for naming an accepted limit instead of hiding it)
- **Tags**: performance, authorization, permissions, tenancy, phase-5

## Context and problem statement

Found while investigating why `Deals/Kanban` is the slowest page in the application. Measured on the live deployment, not inferred — 300 deal cards, each serialised with two `Gate::allows` calls:

| | 600 calls |
|---|---|
| full board serialisation | 399 ms |
| of which `Gate::allows` | 283 ms |
| of which `$user->can('deals.edit')` | 212 ms |
| `Permissions::restrictedToOwnRecords` (a `hasRole`) | 12 ms |
| the same value, memoised | **0.4 ms** |

`DealPolicy::update()` is `$user->can('deals.edit') && $this->isWithinOwnRecords($user, $deal)`. The second half genuinely varies per row — it compares `owner_user_id`. **The first half cannot vary**: it is the user's permission set, recomputed 600 times for an answer that is fixed for the whole request. That is roughly 71% of the board's cost, and the same pattern runs on every list in the application (`$user->can(...)` appears 106 times across 21 policies).

Eager-loading `roles.permissions` was measured too: 156 ms → 111 ms. It helps, but it is not the problem. The cost is not a query, it is re-evaluation.

The obvious alternative — deferring the board's `columns` like every other list — was implemented as an experiment and **rejected on evidence**. `DealStageController::move()` returns `back()`, so Inertia re-issues a full GET of the board after every move; a probe counted the skeleton reappearing on re-entry. Deferring would have traded one 450 ms entry for a blanked board after every drag-and-drop, on the page whose whole point is direct manipulation.

## Decision drivers

- **Per-row authorization must stay per-row.** [[ADR-011]] and §7.5 narrow an Agent to their own records. Anything that memoises a check carrying a model turns a performance fix into privilege escalation.
- **Roles are per tenant.** `config/permission.php` sets `'teams' => true` with `team_foreign_key = tenant_id`: the same person is Owner in one organisation and Viewer in another.
- **The tenant really does change mid-request.** `UpdateMemberRoleAction` and `RevokeInvitationAction` call `setPermissionsTeamId()` for the target member's tenant, and system jobs iterate tenants in one process ([[ADR-014]], `TenantContext::run`).
- **No new global state.** The project's memory budget rules out anything that holds a heap, and a static cache would leak across tests, which run many requests in one process.

## Considered options

### Option 1: memoise on the User instance, keyed by tenant, only for model-less checks (CHOSEN)

`User::can()` is overridden. When the ability is a single string and `$arguments === []`, the result is cached in a private array keyed `{tenant}|{ability}`. Every other shape goes to the parent untouched.

- **Pro**: the memo's lifetime is the model instance's, so it is request-scoped by construction — no container binding, no service provider, no static, nothing to leak between tests.
- **Pro**: one seam. All 106 policy call sites benefit without being edited, so there is no call site left behind.
- **Con**: an instance memo cannot see a mutation applied through a *different* instance of the same row. Named under Consequences.

### Option 2: memoise inside a `Gate::before` callback registered ahead of Spatie's (REJECTED)

Would cover the same calls, but `Gate::before` receives *every* gate check, including the ones carrying a model, and it short-circuits all of them when it returns non-null. The blast radius of a mistake is the entire authorization layer rather than one method, for the same benefit.

### Option 3: change the 106 call sites to a memoising helper (REJECTED)

Mechanical, reviewable — and exactly the kind of change where the one call site that gets missed is the one that matters. It also leaves future policies free to reintroduce the cost silently.

### Option 4: eager-load roles and permissions on the authenticated user (REJECTED as a substitute)

Measured at ~29% off the hot path, and it would load the full role/permission graph on *every* request, including the many that check nothing. Not enough benefit, paid for on the wrong requests.

## Decision

Override `can()` on `App\Models\User`. Memoise **only** `is_string($abilities) && $arguments === []`. Key on `PermissionRegistrar::getPermissionsTeamId()` plus the ability name.

Invalidate in `setRelation()` and `unsetRelation()` for the `roles` and `permissions` relations.

**The invalidation hook was chosen from the library's source, after a test failed.** The first implementation overrode `forgetCachedPermissions()`, which every mutator appears to call. `HasRoles::removeRole()` says otherwise:

```php
if ($this instanceof Permission) {
    $this->forgetCachedPermissions();
}
```

Never for a `User`. The hook was dead, and the memo would have survived a `syncRoles()` — a demotion with no effect for the rest of the request. What *is* called on every mutation path (`assignRole`, `removeRole`, `syncRoles`, `givePermissionTo`, `revokePermissionTo`, `syncPermissions`) is `unsetRelation('roles'|'permissions')` or `setRelation(…, collect())`. That is where the guard lives.

## Consequences

- The board's dominant cost disappears, and every list page that renders per-row `can` gets the same reduction.
- **`tests/Feature/Auth/PermissionMemoTest.php` is the guard, and it was mutation-tested rather than trusted.** Its first version asserted through `Gate::allows()` and kept passing when the memo was deliberately widened to cover model checks — because `Gate::allows()` enters the policy directly and never touches `User::can()`. It now asserts through `$user->can($ability, $model)`, the seam `ContactResource`, `SavedViewResource` and `StageResource` actually use per row, and it fails on that mutation.
- **The tenant in the key is belt-and-braces today, not the load-bearing part, and the test does not prove otherwise.** Removing the tenant from the key leaves the suite green: the relation hooks already clear the memo whenever the roles relation is reloaded, so a stale team key never gets the chance to matter. It stays because it costs nothing and because it forecloses a version of Spatie that re-evaluates on `setPermissionsTeamId()` without touching the relation — in which case the memo itself would become the source of staleness. Claiming a proof here would be claiming one the mutation test refuses to give.
- **A pre-existing behaviour this ADR does not fix**: calling `setPermissionsTeamId()` on an instance that has already loaded `roles` does not re-evaluate anything — Spatie reads the loaded relation. Verified identical with the memo disabled, so it is the library's, neither caused nor repaired here. `PermissionMemoTest` calls `unsetRelation('roles')` explicitly for exactly this reason.
- **The named limit**: the memo lives on one model instance. If the authenticated user's roles were changed through another instance of the same row, within the same request, `auth()`'s instance would keep the old answer. It does not happen today — all three actions that change roles operate on the *target* member, not the actor, and write an activity-log row and return without checking another permission. If that changes, `User::can()` is where to look.
