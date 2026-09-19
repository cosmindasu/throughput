<?php

namespace App\Policies;

use App\Models\Import;
use App\Models\User;

/**
 * Matricea §7.4, rândul „Import CSV": Owner CRUD, Manager CRUD, Agent „—", Viewer „—".
 * Catalogul (`App\Support\Permissions`, neatins de acest lot) expune doar
 * `imports.view`/`imports.create` — fără `imports.edit`/`imports.delete` separate, fiindcă
 * fluxul în 4 pași (mapare/dry-run/commit) e o continuare a ACELUIAȘI „creare", nu o editare
 * ulterioară a unei resurse persistente (spre deosebire de un cont sau un deal).
 *
 * FĂRĂ ÎNGUSTARE PE PROPRIETATE — spre deosebire de `OrderPolicy`/`AccountPolicy`: matricea
 * dă Agentului „—", nu „CRUD*", deci nu există niciun subset „propriu" de importuri pe care
 * un Agent să-l vadă. Un Owner/Manager vede TOATE importurile tenantului, nu doar ale lui.
 */
final class ImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('imports.view');
    }

    public function view(User $user, Import $import): bool
    {
        return $user->can('imports.view');
    }

    /**
     * Acoperă și continuarea fluxului pe un import existent (mapare, dry-run, commit,
     * descărcare template/raport de erori) — vezi docblock-ul clasei.
     */
    public function create(User $user): bool
    {
        return $user->can('imports.create');
    }

    /**
     * Anulare (P1, review general) — aceeași permisiune ca restul fluxului de scriere;
     * starea (deja terminal sau nu) e verificată de `CancelImportAction`, nu aici (§7.5 —
     * un Policy răspunde la DREPT, nu la starea curentă exactă).
     */
    public function cancel(User $user, Import $import): bool
    {
        return $user->can('imports.create');
    }
}
