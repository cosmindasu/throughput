<?php

namespace App\Actions\Imports;

use App\Models\Import;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Imports\ImportConcurrencyGuard;
use App\Support\Imports\ImportFilePath;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Pasul 1 — Upload (§14.1). Creează rândul `imports` (`status = uploaded`) și persistă
 * fișierul pe disc (`Storage::disk('local')`, ca la `ExportListJob`) — NIMIC altceva se
 * întâmplă în cererea HTTP: antetul se citește separat, la cerere, de `ImportController::show()`
 * (Pasul 2), nu aici (ADR-013 — chiar și o citire ieftină rămâne opțională dacă nu e nevoie
 * de ea imediat).
 *
 * §22.5 — un singur import activ per tenant (`ImportConcurrencyGuard`), verificat AICI, nu
 * doar ascuns în UI: al doilea upload concurent primește un refuz clar, nu o coadă tăcută.
 *
 * P2 (review general) — `SELECT COUNT` urmat de `INSERT` separat era o cursă reală: două
 * upload-uri simultane treceau amândouă garda, fiindcă niciuna nu vedea INSERT-ul celeilalte
 * înainte de propriul COUNT. `Tenant` blocat cu `->lock('for no key update')` ÎNAINTEA
 * verificării — tiparul din `.ai/rules/tenancy.md` („Blocarea unui rând părinte"), deja
 * folosit identic în `PrimaryContactAssignment`/`SaveStageAction`: a doua cerere așteaptă
 * commit-ul primei (deci vede deja noul import „uploaded" la propriul COUNT), fără să
 * blocheze INSERT-urile din alte tabele copil ale tenantului (`FOR NO KEY UPDATE`, nu `FOR
 * UPDATE` — acela ar intra în conflict cu `FOR KEY SHARE`, blocarea de verificare FK a
 * oricărui INSERT într-un rând copil, oprind tot tenantul până la finalul cererii).
 */
final class CreateImportAction
{
    public function execute(User $user, string $resourceType, UploadedFile $file): Import
    {
        Tenant::query()->whereKey(TenantScope::requireCurrentTenantId())->lock('for no key update')->first();

        if (ImportConcurrencyGuard::hasReachedLimit()) {
            throw ValidationException::withMessages([
                'file' => 'This workspace already has an import in progress. Finish or wait for it to complete before starting another (only one active import per workspace).',
            ]);
        }

        $import = new Import([
            'resource_type' => $resourceType,
            'original_filename' => $file->getClientOriginalName(),
            'status' => Import::STATUS_UPLOADED,
        ]);
        $import->created_by = $user->getKey();
        $import->save();

        $path = ImportFilePath::for($import);
        $file->storeAs(dirname($path), basename($path), ImportFilePath::DISK);

        return $import;
    }
}
