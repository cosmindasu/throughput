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

    /**
     * GDPR-04, §13.4/§13.5 (Art. 21) — opt-out în masă (Pachetul C, valul „bulk"). Legată
     * de `contacts.edit`, ca `update()`: Agent trece Policy-ul (are `contacts.edit`), dar
     * rămâne restrâns la subsetul propriu la nivel de INTEROGARE
     * (`App\Support\Bulk\Resources\ContactBulkResource::scopeToOwnRecords()`), simetric cu
     * `AccountPolicy::bulkReassignOwner()` — un Policy răspunde la „poate omul ăsta
     * declanșa ACȚIUNEA", nu la „pe ce rânduri anume". Viewer n-are `contacts.edit` →
     * refuzat (BR-BULK-03).
     */
    public function bulkOptOut(User $user): bool
    {
        return $user->can('contacts.edit') && $user->can('bulk.write');
    }

    /**
     * GDPR-04, §13.4/§13.5 (Art. 17, RTBF) — ștergere/anonimizare în masă. Legată de
     * `contacts.delete`, ca `delete()` de mai sus; îngustarea la subsetul propriu al
     * Agentului e tot la nivel de interogare, nu aici.
     */
    public function bulkDelete(User $user): bool
    {
        return $user->can('contacts.delete') && $user->can('bulk.write');
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
