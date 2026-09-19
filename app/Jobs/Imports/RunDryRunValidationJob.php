<?php

namespace App\Jobs\Imports;

use App\Jobs\Middleware\ApplyTenantContextToJob;
use App\Models\Import;
use App\Models\ImportRow;
use App\Services\Tenancy\TenantContext;
use App\Support\Imports\ImportableResources;
use App\Support\Imports\ImportDryRunChunkProcessor;
use App\Support\Imports\ImportFilePath;
use App\Support\Imports\ImportRowsRangeReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pasul 3 — Probă uscată (§14.1 pct. 3, US-IMP-01). Job de TENANT (ADR-014): constructorul
 * primește scalari (`tenantId`, `importId`), niciodată modele.
 *
 * UN SINGUR CHUNK per invocare, apoi se RE-DISPECERIZEAZĂ SINGUR pe coada `imports`
 * (Horizon, `['imports', 'reports', 'bulk', 'default']`) până termină fișierul — NU
 * `Maatwebsite\Excel` cu `ShouldQueue` pe clasa de citire, cum sugerează literal §14.1.
 * Motiv măsurat, nu stilistic: `config/horizon.php` fixează supervisorul unic la
 * `'timeout' => 60, 'tries' => 1` — un singur job care ar citi/valida un fișier de
 * 50.000 rânduri (`import_max_rows`) cu `Excel::import()` sincron ar depăși cu mult 60 de
 * secunde (măsurat: ~500 rânduri/secundă cu validare + o interogare batched per chunk, deci
 * ~100 de secunde doar pentru 50.000 rânduri) și ar fi omorât de Horizon FĂRĂ reîncercare
 * (`tries = 1`), lăsând importul blocat la `validating` pentru totdeauna. Mecanismul propriu
 * de `ShouldQueue` al pachetului (`ReadChunk`/`AfterImportJob`/`QueueImport`, verificat în
 * sursă) ar rezolva timeout-ul, dar ar sparge restaurarea contextului de tenant: acele clase
 * nu cunosc `ApplyTenantContextToJob`, iar starea internă a clasei de citire (setul „văzut în
 * fișier" pentru duplicate) NU supraviețuiește serializării între chunk-uri — fiecare
 * `ReadChunk` deserializează o COPIE proaspătă. Soluția aici ține fiecare invocare mică
 * (un chunk = o tranzacție scurtă = un job normal, exact tiparul deja folosit de operațiile
 * în masă), iar duplicatele ÎN FIȘIER se verifică într-o trecere finală separată
 * (`ImportDryRunFinalizer`, prin `FinalizeImportDryRunJob`), care citește direct din bază —
 * nu din starea (volatilă) a unui obiect de citire.
 *
 * Reluarea poziției: `MAX(row_number)` deja scris în `import_rows` pentru acest import — nu
 * o coloană nouă pe `imports` (task brief: nicio cheie de config/coloană nouă fără aprobare).
 */
class RunDryRunValidationJob implements ShouldQueue
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

        if ($import === null || ! in_array($import->status, [Import::STATUS_MAPPED, Import::STATUS_VALIDATING], true)) {
            return;
        }

        if ($import->status === Import::STATUS_MAPPED) {
            $import->update(['status' => Import::STATUS_VALIDATING]);
        }

        $resumeRow = (int) (ImportRow::query()->where('import_id', $this->importId)->max('row_number') ?? 1) + 1;
        $resumeRow = max(2, $resumeRow);

        $extension = ImportFilePath::extension($import);
        $absolutePath = Storage::disk(ImportFilePath::DISK)->path(ImportFilePath::for($import));
        $chunkSize = max(1, (int) config('throughput.limits.import_chunk_size'));

        $range = ImportRowsRangeReader::read($absolutePath, $extension, $resumeRow, $chunkSize);

        $resource = ImportableResources::resolve($import->resource_type);
        $processor = new ImportDryRunChunkProcessor($resource, (array) $import->column_mapping);
        $result = $processor->process($this->importId, $range['rows']);

        if ($range['rows'] !== []) {
            Import::query()->whereKey($this->importId)->update([
                'total_rows' => DB::raw('coalesce(total_rows, 0) + '.count($range['rows'])),
                'valid_rows' => DB::raw('coalesce(valid_rows, 0) + '.$result['validCount']),
                'error_rows' => DB::raw('coalesce(error_rows, 0) + '.$result['errorCount']),
            ]);
        }

        if (! $range['isLastChunk']) {
            self::dispatch($this->tenantId, $this->importId)->onQueue('imports');

            return;
        }

        FinalizeImportDryRunJob::dispatch($this->tenantId, $this->importId)->onQueue('imports');
    }

    /**
     * Plasă de siguranță (tiparul `ExportListJob`) — pentru un job ucis abrupt sau o
     * excepție scăpată de peste tot: importul nu rămâne blocat la `validating` la infinit,
     * iar `Imports/Show.tsx` (polling) nu așteaptă un progres care nu va mai veni.
     */
    public function failed(Throwable $e): void
    {
        TenantContext::run($this->tenantId, function (): void {
            // `completed_at` — vezi nota din `CommitImportJob::failed()` (retenția fișierului,
            // `PruneExpiredImportFilesJob`).
            Import::query()->whereKey($this->importId)->update([
                'status' => Import::STATUS_FAILED,
                'completed_at' => now(),
            ]);
        });

        report($e);
    }
}
