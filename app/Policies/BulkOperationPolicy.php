<?php

namespace App\Policies;

use App\Models\BulkOperation;
use App\Models\User;

/**
 * Pagina de status (`exports.show`) și descărcarea (`exports.download`) ale unei operații
 * în masă (§13.2) — RLS + global scope garantează deja tenantul; policy-ul răspunde la
 * „a utilizatorului ăsta e operația asta". Fără o regulă separată de rol: e propriul
 * export al oricui l-a declanșat, indiferent dacă e Owner sau Viewer (US-CRM-03).
 */
class BulkOperationPolicy
{
    public function view(User $user, BulkOperation $bulkOperation): bool
    {
        return $bulkOperation->user_id === $user->getKey();
    }

    public function download(User $user, BulkOperation $bulkOperation): bool
    {
        return $bulkOperation->isDownloadableBy($user);
    }
}
