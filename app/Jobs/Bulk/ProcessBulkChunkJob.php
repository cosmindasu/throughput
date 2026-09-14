<?php

namespace App\Jobs\Bulk;

use App\Jobs\Middleware\ApplyTenantContextToJob;
use App\Support\Bulk\BulkChunkActions;
use App\Support\Bulk\BulkWritableResources;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job de CHUNK (§13.2, pct. 3/5) — job de TENANT (ADR-014), FĂRĂ I/O extern, deci întreg
 * `handle()` poate sta într-o SINGURĂ tranzacție prin `ApplyTenantContextToJob` — spre
 * deosebire de `PlanBulkOperationJob`/`ExportListJob`, care au nevoie de vizibilitate
 * intermediară (starea „running" trebuie comisă separat de restul).
 *
 * Idempotent prin construcție (executorul de acțiune scrie un `UPDATE` condiționat pe
 * stare, niciodată un increment) — sigur la reîncercare (`tries`).
 *
 * Verifică `$this->batch()->cancelled()` la ÎNCEPUTUL lui `handle()` (§13.2, pct. 7):
 * job-urile deja pornite se termină, cele neîncepute se opresc cooperativ — Laravel nu
 * omoară joburi în execuție automat.
 */
class ProcessBulkChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $tenantId,
        public string $bulkOperationId,
        public string $resourceType,
        public string $action,
        public array $ids,
        public array $payload,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new ApplyTenantContextToJob];
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $resource = BulkWritableResources::resolve($this->resourceType);
        $executor = BulkChunkActions::resolve($this->action);

        $executor->apply($resource, $this->ids, $this->payload);
    }
}
