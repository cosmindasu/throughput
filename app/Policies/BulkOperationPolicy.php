<?php

namespace App\Policies;

use App\Models\BulkOperation;
use App\Models\User;

/**
 * Pagina de status (`exports.show`/`bulk.show`), descărcarea (`exports.download`) și
 * anularea (`bulk.cancel`, §13.2 pct. 7) unei operații în masă — RLS + global scope
 * garantează deja tenantul; policy-ul răspunde la „a utilizatorului ăsta e operația asta".
 * Fără o regulă separată de rol: e propria operație a oricui a declanșat-o, indiferent dacă
 * e Owner sau Viewer (US-CRM-03) — și, la fel, doar autorul o poate anula.
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

    /**
     * §13.2, pct. 7 — doar autorul poate declanșa `$batch->cancel()`. Nicio verificare de
     * stare aici (a doua apăsare pe o operație deja terminată e un `cancel()` fără efect,
     * nu o eroare de autorizare).
     */
    public function cancel(User $user, BulkOperation $bulkOperation): bool
    {
        return $bulkOperation->user_id === $user->getKey();
    }
}
