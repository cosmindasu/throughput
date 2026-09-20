<?php

namespace App\Policies;

use App\Models\DataExportRequest;
use App\Models\User;

/**
 * BR-GDPR-01 (specs.md §20.5) — „doar Owner poate declanșa un export nou; Manager poate
 * vedea istoricul, dar nu poate declanșa unul nou".
 *
 * Regula NU e rescrisă aici din rol: permisiunile `data_exports.view` și
 * `data_exports.create` există în `App\Support\Permissions` din Faza 1, iar diferența dintre
 * cele două roluri e deja codificată acolo (`forRoles()` scoate `data_exports.create` din
 * setul Managerului prin `array_diff`). Policy-ul doar le citește — un `hasRole(OWNER)` de
 * aici ar fi a doua sursă de adevăr pentru aceeași matrice §7.4.
 *
 * Înregistrare: prin auto-discovery (`App\Models\DataExportRequest` → `App\Policies\
 * DataExportRequestPolicy`), ca toate celelalte politici ale proiectului — nu există niciun
 * `AuthServiceProvider` și nicio hartă explicită de politici.
 */
class DataExportRequestPolicy
{
    /**
     * FR-GDPR-02 — istoricul (status, dată, cine a declanșat) e vizibil pentru Owner ȘI
     * Manager. Agent și Viewer n-au niciuna dintre cele două permisiuni, deci secțiunea
     * lipsește complet din Settings pentru ei (FR-RBAC-01: un ecran fără drept e ABSENT).
     */
    public function viewAny(User $user): bool
    {
        return $user->can('data_exports.view');
    }

    public function create(User $user): bool
    {
        return $user->can('data_exports.create');
    }

    /**
     * Descărcarea e îngustată la AUTORUL cererii, peste permisiune — precedentul e
     * `BulkOperation::isDownloadableBy()`, care aplică exact aceeași regulă fișierelor de
     * export de listă. Cum numai Owner-ul poate declanșa un export (BR-GDPR-01), efectul e
     * că arhiva ajunge doar la Owner-ul care a cerut-o.
     *
     * Specificația nu tranșează dacă un AL DOILEA Owner (sau un Manager, care vede
     * istoricul) ar trebui să poată descărca arhiva altcuiva — semnalat în raportul lotului.
     * Am ales varianta strictă fiindcă arhiva e un singur fișier cu tot workspace-ul
     * înăuntru, iar o a doua persoană care are nevoie de ea poate cere un export propriu,
     * cu un click, lăsând în istoric urma faptului că l-a cerut.
     *
     * `status`/`file_path`/`expires_at` se verifică tot aici, nu în controller: altfel un
     * link vechi, dintr-un email de acum două săptămâni, ar ocoli tăcut expirarea.
     */
    public function download(User $user, DataExportRequest $dataExportRequest): bool
    {
        return $user->can('data_exports.view')
            && $dataExportRequest->requested_by === $user->getKey()
            && $dataExportRequest->isDownloadable();
    }
}
