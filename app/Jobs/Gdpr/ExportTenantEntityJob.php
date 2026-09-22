<?php

namespace App\Jobs\Gdpr;

use App\Actions\Gdpr\DataExportSources;
use App\Actions\Gdpr\WriteEntityExportAction;
use App\Models\DataExportRequest;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Throwable;

/**
 * Un job de batch per entitate (accounts, contacts, deals, orders, invoices, payments,
 * activity_log) — echivalentul lui `App\Jobs\Bulk\ProcessBulkChunkJob` din mecanismul de
 * operații în masă, cu aceeași proprietate: fiecare job ține o tranzacție SCURTĂ, proprie,
 * în loc ca un singur job lung să țină una cât tot exportul (ADR-013).
 *
 * Job de TENANT (ADR-014, pct. 4): `tenantId` scalar, context restaurat aici. Izolarea vine
 * din stratul 1 (global scope) + stratul 2 (RLS) — nu există niciun `where tenant_id` în
 * `App\Actions\Gdpr\DataExportSource::newQuery()`, deliberat (specs.md §20.5).
 *
 * Idempotent: rescrie fișierele entității de la zero la fiecare rulare, deci o redelivrare
 * după timeout nu dublează nimic. De-asta `$tries = 2` e sigur aici, spre deosebire de
 * planificator.
 *
 * ADR-022, specs.md §15.8 FR-I18N-05 — `locale` e SCALAR de constructor, exact ca
 * `tenantId`/`dataExportRequestId` (ADR-013/014), rezolvat de `App\Jobs\Gdpr\PlanDataExportJob`
 * ÎNAINTE de dispecerizare, din `users.locale` al Owner-ului care a cerut exportul. E jobul
 * care CHIAR evaluează `App\Actions\Gdpr\DataExportSources::all()` (prin `resolve()`) —
 * `label`/`note` trec acum prin `lang/gdpr.php` — deci e și jobul care are nevoie de
 * `App::setLocale()`, nu doar `App\Jobs\Gdpr\FinalizeDataExportJob`, care doar CITEȘTE
 * valorile deja scrise în `{entitate}.meta.json` de acesta. Lipsa asta era defectul real
 * (semnalat la verificarea Valului 5, a treia trecere): fără ea, un worker de coadă de
 * viață lungă (`.ai/rules/tenancy.md:123-138`) ar fi păstrat limba lăsată de exportul
 * ANTERIOR procesat pe același worker — vezi `tests/Feature/I18n/JobLocaleLeakTest.php`.
 */
class ExportTenantEntityJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * Sub `retry_after` al cozii Redis (900s), ca workerul să nu primească o a doua copie a
     * aceluiași job cât prima încă scrie în aceleași fișiere.
     */
    public int $timeout = 300;

    public function __construct(
        public string $tenantId,
        public string $dataExportRequestId,
        public string $entity,
        public string $locale = 'en',
    ) {}

    public function handle(): void
    {
        // Vezi docblock-ul clasei — obligatoriu la ÎNCEPUTUL lui handle(), necondiționat
        // (nu doar „dacă diferă de ce e setat deja"): un worker de viață lungă n-are niciun
        // alt semnal de încredere despre ce a lăsat jobul anterior în urmă.
        App::setLocale($this->locale);

        if ($this->batch()?->cancelled()) {
            return;
        }

        TenantContext::run($this->tenantId, function (): void {
            $export = DataExportRequest::query()->find($this->dataExportRequestId);

            // Cererea a dispărut sau a fost deja închisă (eșecul altei entități a anulat
            // batch-ul): nu mai are rost să scriem fișiere pe care nimeni nu le arhivează.
            if ($export === null || $export->status !== DataExportRequest::STATUS_PROCESSING) {
                return;
            }

            app(WriteEntityExportAction::class)->execute(
                DataExportSources::resolve($this->entity),
                $this->tenantId,
                $this->dataExportRequestId,
            );
        });
    }

    /**
     * Mesajul care ajunge pe ecran spune CE entitate a căzut — „Something went wrong" e
     * exact ce interzice US-ORD-03 pentru eticheta de curierat, iar motivul e același:
     * omul care a cerut exportul trebuie să poată spune de ce a eșuat, nu doar că a eșuat.
     *
     * Starea TERMINALĂ rămâne treaba lui `FinalizeDataExportJob` (rulează oricum, prin
     * `finally()`-ul batch-ului); aici se scrie doar mesajul, iar acolo el NU se
     * suprascrie — altfel eșecul specific ar fi înlocuit de unul generic.
     */
    public function failed(Throwable $e): void
    {
        $entity = $this->entity;

        TenantContext::run($this->tenantId, function () use ($entity): void {
            $export = DataExportRequest::query()->find($this->dataExportRequestId);

            if ($export === null || $export->status !== DataExportRequest::STATUS_PROCESSING) {
                return;
            }

            $export->update([
                'error_message' => "The export stopped while writing the \"{$entity}\" file. Nothing was delivered; request a new export to try again.",
            ]);
        });

        report($e);
    }
}
