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
    ) {}

    public function handle(): void
    {
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
