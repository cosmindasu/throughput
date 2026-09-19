<?php

namespace App\Jobs\Imports;

use App\Jobs\Middleware\ApplyTenantContextToJob;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Imports\ImportableResources;
use App\Support\Imports\ImportRowMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pasul 4 — Commit (§14.1 pct. 4). Job de TENANT, UN CHUNK per invocare, auto-continuare —
 * exact tiparul din `RunDryRunValidationJob` (Horizon `timeout = 60` / `tries = 1`, motivat
 * acolo).
 *
 * Reluarea NU cere un cursor separat: fiecare invocare cere „următoarele `import_chunk_size`
 * rânduri ÎNCĂ `valid`, ordonate după `row_number`" — o dată procesat, statusul unui rând
 * trece la `imported` (succes) sau `invalid` (eșec la scriere, vezi mai jos), deci iese
 * automat din filtru la invocarea următoare. Terminarea se detectează prin interogare goală,
 * nu printr-un contor separat.
 *
 * Ingestie PARȚIALĂ (§14.1 pct. 4, niciodată all-or-nothing): un eșec la scrierea UNUI rând
 * (ex: cursă rară cu un duplicat creat concurent) marchează DOAR acel rând `invalid` și
 * continuă cu restul chunk-ului — nu oprește, nu anulează rândurile deja commit-uite.
 * `raw_data` nu se atinge niciodată (BR-IMP-01).
 */
class CommitImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(
        public string $tenantId,
        public string $importId,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new ApplyTenantContextToJob];
    }

    public function handle(): void
    {
        $import = Import::query()->find($this->importId);

        if ($import === null || ! in_array($import->status, [Import::STATUS_VALIDATED, Import::STATUS_IMPORTING], true)) {
            return;
        }

        if ($import->status === Import::STATUS_VALIDATED) {
            $import->update(['status' => Import::STATUS_IMPORTING]);
        }

        $chunkSize = max(1, (int) config('throughput.limits.import_chunk_size'));

        $rows = ImportRow::query()
            ->where('import_id', $this->importId)
            ->where('status', ImportRow::STATUS_VALID)
            ->orderBy('row_number')
            ->limit($chunkSize)
            ->get();

        if ($rows->isEmpty()) {
            $fresh = $import->fresh();
            $status = ($fresh?->error_rows ?? 0) > 0 ? Import::STATUS_COMPLETED_WITH_ERRORS : Import::STATUS_COMPLETED;
            $fresh?->update(['status' => $status, 'completed_at' => now()]);

            return;
        }

        $resource = ImportableResources::resolve($import->resource_type);
        $columnMapping = (array) $import->column_mapping;
        $user = User::query()->find($import->created_by);

        // Mapate O SINGURĂ DATĂ, înainte de buclă — `prepareChunk()` are nevoie de TOATE
        // rândurile chunk-ului deodată (P2, review general — N+1 la commit: fără ea,
        // `Contacts`/`Variants` rulau o interogare de căutare a părintelui PER RÂND; acum
        // rezolvă harta „nume → id" o singură dată, per chunk, mai jos).
        $mappedByRowKey = [];

        foreach ($rows as $row) {
            $mappedByRowKey[$row->getKey()] = ImportRowMapper::apply((array) $row->raw_data, $columnMapping);
        }

        $resource->prepareChunk(array_values($mappedByRowKey));

        foreach ($rows as $row) {
            /** @var ImportRow $row */
            try {
                // `DB::transaction()` ÎNCĂ O DATĂ, deși `ApplyTenantContextToJob` a deschis
                // deja una pentru tot chunk-ul: Laravel o transformă într-un SAVEPOINT (nu o
                // tranzacție imbricată reală). Fără el, un eșec la scrierea UNUI rând (ex:
                // constrângerea unică pe SKU, într-o cursă foarte rară) ar lăsa toată
                // tranzacția POSTGRES „aborted" — `.ai/rules/tenancy.md`, „un create() într-un
                // catch pentru unique violation nu salvează nimic: orice eroare abortează
                // tranzacția" — deci `$row->update()` din `catch` de mai jos ar EȘUA la rândul
                // lui, iar excepția nou apărută ar scăpa necaptată, terminând tot jobul (și
                // pierzând rândurile DEJA scrise cu succes în acest chunk, exact opusul
                // „niciodată all-or-nothing"). Cu savepoint-ul, doar rândul curent se
                // derulează înapoi — restul chunk-ului rămâne intact.
                $entity = DB::transaction(function () use ($row, $mappedByRowKey, $resource, $user) {
                    return $resource->writeRow($mappedByRowKey[$row->getKey()], $user);
                });

                $row->update([
                    'status' => ImportRow::STATUS_IMPORTED,
                    'created_entity_id' => $entity->getKey(),
                ]);
            } catch (Throwable $e) {
                $row->update([
                    'status' => ImportRow::STATUS_INVALID,
                    'errors' => [[
                        'field' => '_row',
                        'message' => 'Could not be imported: '.$e->getMessage(),
                    ]],
                ]);

                Import::query()->whereKey($this->importId)->update([
                    'valid_rows' => DB::raw('greatest(coalesce(valid_rows, 0) - 1, 0)'),
                    'error_rows' => DB::raw('coalesce(error_rows, 0) + 1'),
                ]);

                report($e);
            }
        }

        self::dispatch($this->tenantId, $this->importId)->onQueue('imports');
    }

    public function failed(Throwable $e): void
    {
        TenantContext::run($this->tenantId, function (): void {
            // `completed_at` setat și pe eșec — `PruneExpiredImportFilesJob` (P1, review
            // general) filtrează pe el pentru retenția fișierului stocat; un import terminal
            // fără `completed_at` n-ar intra niciodată în retenție.
            Import::query()->whereKey($this->importId)->update([
                'status' => Import::STATUS_FAILED,
                'completed_at' => now(),
            ]);
        });

        report($e);
    }
}
