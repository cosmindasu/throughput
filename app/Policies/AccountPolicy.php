<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Support\Permissions;

/**
 * Matricea §7.4, rândul „Conturi", plus îngustarea ABAC din §7.5.
 *
 * Global scope-ul și RLS garantează deja că un cont din alt tenant nu ajunge aici. Policy-ul
 * răspunde la cealaltă întrebare: ce are voie utilizatorul să facă cu un cont pe care îl
 * VEDE. Pentru Agent, răspunsul depinde de cont, nu doar de rol.
 *
 * Regula de ștergere BR-CRM-01 (cont cu deals sau comenzi) NU e aici, ci în
 * `Account::deletionBlockedReason()`: e o stare a contului, nu un drept al utilizatorului.
 * Amestecate, `can.delete` ar fi ascuns butonul, iar utilizatorul n-ar fi aflat niciodată
 * de ce nu poate șterge — exact „aplicația stricată" din §7.3.
 */
class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounts.view');
    }

    public function view(User $user, Account $account): bool
    {
        return $user->can('accounts.view');
    }

    public function create(User $user): bool
    {
        return $user->can('accounts.create');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->can('accounts.edit') && $this->isWithinOwnRecords($user, $account);
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->can('accounts.delete') && $this->isWithinOwnRecords($user, $account);
    }

    /**
     * Exportul e o CITIRE (§7.4 nota ³, BR-BULK-03), deci îl are și Viewer-ul: `bulk.export`
     * separat de `bulk.write`.
     */
    public function export(User $user): bool
    {
        return $user->can('accounts.view') && $user->can('bulk.export');
    }

    /**
     * §13.4 (BR-BULK-02/03) — reasignare owner în masă (Pachetul C, valul „bulk"). Agent
     * trece Policy-ul (are `accounts.edit`), dar rămâne restrâns la subsetul propriu la
     * nivel de INTEROGARE (`App\Support\Bulk\Resources\AccountBulkResource::scopeToOwnRecords()`),
     * nu aici — un Policy răspunde la „poate omul ăsta declanșa ACȚIUNEA", nu la „pe ce
     * rânduri anume"; plafonul de rânduri (500) e verificat separat, în
     * `DispatchBulkOperationAction`. Viewer n-are `accounts.edit` → refuzat (BR-BULK-03).
     */
    public function bulkReassignOwner(User $user): bool
    {
        return $user->can('accounts.edit') && $user->can('bulk.write');
    }

    /**
     * §7.5: un Agent lucrează pe conturile unde e responsabil sau pe cele create de el.
     */
    private function isWithinOwnRecords(User $user, Account $account): bool
    {
        if (! Permissions::restrictedToOwnRecords($user)) {
            return true;
        }

        return $account->owner_user_id === $user->getKey() || $account->created_by === $user->getKey();
    }
}
