<?php

namespace App\Actions\Gdpr;

use App\Jobs\Gdpr\PlanDataExportJob;
use App\Models\DataExportRequest;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * FR-GDPR-01 / US-GDPR-01 — „Request export". TOT ce se întâmplă în cererea HTTP e un
 * `INSERT` și un `dispatch`: interogarea celor șapte entități, serializarea și compresia
 * aparțin cozii (ADR-013 — middleware-ul de context ține o tranzacție deschisă pe toată
 * durata cererii, pe un container cu `max_connections=30`).
 *
 * **O singură cerere activă per workspace**, verificată aici, nu doar ascunsă în interfață:
 * un export atinge fiecare tabelă de volum a tenantului, iar workerul de coadă e UNUL
 * singur (ADR-017), cu `memory_limit=256M`. Două exporturi concurente n-ar produce nimic în
 * plus — ar dubla vârful de memorie al singurului worker și ar întârzia tot restul cozii.
 * Tiparul e cel din `App\Actions\Imports\CreateImportAction` (§22.5, „un singur import activ
 * per tenant"), inclusiv blocarea rândului părinte ÎNAINTE de verificare: fără ea, două
 * cereri simultane trec amândouă de `exists()`, fiindcă niciuna nu vede INSERT-ul celeilalte.
 * `->lock('for no key update')`, nu `lockForUpdate()` — `.ai/rules/tenancy.md`: `FOR UPDATE`
 * pe `tenants` intră în conflict cu `FOR KEY SHARE`, blocarea luată de verificarea FK a
 * oricărui INSERT în orice tabelă copil, adică ar opri scrierile întregului tenant până la
 * finalul cererii.
 */
final class RequestDataExportAction
{
    public function execute(User $user): DataExportRequest
    {
        $tenantId = TenantScope::requireCurrentTenantId();

        Tenant::query()->whereKey($tenantId)->lock('for no key update')->first();

        $inProgress = DataExportRequest::query()
            ->whereIn('status', [DataExportRequest::STATUS_QUEUED, DataExportRequest::STATUS_PROCESSING])
            ->exists();

        if ($inProgress) {
            throw ValidationException::withMessages([
                'export' => trans('rules.gdpr.export_already_running'),
            ]);
        }

        $export = new DataExportRequest([
            'status' => DataExportRequest::STATUS_QUEUED,
            'requested_at' => now(),
        ]);
        $export->requested_by = $user->getKey();
        $export->save();

        // `tenantId` scalar, niciodată modelul (§6.3/ADR-014): un `DataExportRequest`
        // deserializat de worker s-ar reîncărca fără context de tenant și ar da 0 rânduri.
        PlanDataExportJob::dispatch($tenantId, $export->getKey())->onQueue('bulk');

        return $export;
    }
}
