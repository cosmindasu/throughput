<?php

namespace App\Support\Contacts;

use App\Models\Account;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Code review P1-001 — `Rule::exists('accounts', 'id')` rulează pe query builder, deci
 * fără global scope-ul Eloquent (ADR-003): un `account_id` dintr-un alt tenant era
 * respins DOAR de RLS, un singur strat, nu ambele.
 *
 * `Account::query()` trece prin `BelongsToTenant` (TenantScope), deci un id dintr-un
 * alt tenant nu există pentru NICIUN strat — o scurgere ar cere ambele să greșească
 * simultan, ca peste tot în rest (ADR-003).
 */
final class AccountBelongsToTenant implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Account::query()->whereKey($value)->exists()) {
            $fail(__('rules.contacts.account_not_in_workspace'));
        }
    }
}
