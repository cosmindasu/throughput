<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;
use App\Support\Permissions;

/**
 * Matricea §7.4, rândul „Contacte", plus îngustarea ABAC din §7.5.
 *
 * Citirea e pe tot tenantul (US-CRM-02, „My accounts" nu are echivalent pentru
 * Contacts): niciun rol nu e restrâns la `view`/`viewAny`. Doar editarea/ștergerea
 * se îngustează pentru Agent — la contactele create de el SAU ale căror cont îl are
 * pe el ca responsabil (`accounts.owner_user_id`). Contactul n-are `owner_user_id`
 * propriu (§8.1): ownership-ul „prin cont" e motivul pentru care verificarea are
 * nevoie de relația `account`, spre deosebire de `AccountPolicy`.
 */
class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contacts.view');
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->can('contacts.view');
    }

    public function create(User $user): bool
    {
        return $user->can('contacts.create');
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->can('contacts.edit') && $this->isWithinOwnRecords($user, $contact);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->can('contacts.delete') && $this->isWithinOwnRecords($user, $contact);
    }

    /**
     * Exportul e o CITIRE (§7.4 nota ³, BR-BULK-03; §13.5 „Contacte: export CSV"), deci îl are
     * și Viewer-ul — aceeași regulă ca la conturi.
     */
    public function export(User $user): bool
    {
        return $user->can('contacts.view') && $user->can('bulk.export');
    }

    private function isWithinOwnRecords(User $user, Contact $contact): bool
    {
        if (! Permissions::restrictedToOwnRecords($user)) {
            return true;
        }

        if ($contact->created_by === $user->getKey()) {
            return true;
        }

        return $contact->account !== null && $contact->account->owner_user_id === $user->getKey();
    }
}
