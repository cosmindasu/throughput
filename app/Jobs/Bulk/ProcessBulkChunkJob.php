<?php

namespace App\Jobs\Bulk;

use App\Jobs\Middleware\ApplyTenantContextToJob;
use App\Models\BulkOperationChunk;
use App\Models\Scopes\TenantScope;
use App\Support\Activity\BulkChunkActivityRecorder;
use App\Support\Bulk\BulkChunkActions;
use App\Support\Bulk\BulkWritableResources;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Job de CHUNK (§13.2, pct. 3/5) — job de TENANT (ADR-014), FĂRĂ I/O extern, deci întreg
 * `handle()` poate sta într-o SINGURĂ tranzacție prin `ApplyTenantContextToJob` — spre
 * deosebire de `PlanBulkOperationJob`/`ExportListJob`, care au nevoie de vizibilitate
 * intermediară (starea „running" trebuie comisă separat de restul).
 *
 * Idempotență PER CHUNK (code review „P2-001", decizia proprietarului) — NU doar prin
 * construcția executorului: un marcaj „ultima operație" pe rândul țintă nu e suficient
 * când efectul depinde de valoarea VECHE a rândului (prețul, de exemplu) — o reîncercare
 * a ACELUIAȘI chunk, cu ACELAȘI marcaj, tot ar dubla efectul dacă nimic n-o oprește
 * ÎNAINTE de `apply()`. Aici: `insertOrIgnore` pe `(bulk_operation_id, chunk)` — un rând
 * per chunk EFECTIV aplicat — chiar în interiorul tranzacției pe care
 * `ApplyTenantContextToJob` → `TenantContext::run()` → `DB::transaction()` o deschide deja
 * pentru tot `handle()`; dacă inserarea întoarce 0, chunk-ul a mai fost aplicat (job
 * redelivrat după un crash între commit și ack — `retry_after`, OOM real pe VPS) și
 * `apply()` NU se mai cheamă. `App\Support\Bulk\BulkChunkAction` rămâne recomandarea de
 * idempotență DE RÂND, acolo unde e posibilă (`UPDATE ... WHERE`) — garanția de aici e
 * independentă de ea și se aplică tuturor acțiunilor, ca plasă de siguranță comună.
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
     * @param  int  $chunk  Indexul chunk-ului, dat de `PlanBulkOperationJob` — stabil și
     *                      determinist în cadrul UNEI planificări (§13.2). Cheia (împreună
     *                      cu `bulkOperationId`) a marcajului de idempotență de mai sus.
     * @param  string|null  $actorUserId  Lotul E — cine a declanșat operația (`bulk_operations.
     *                                    user_id`, propagat prin `filter_snapshot`), pentru rândurile de
     *                                    `activity_log` scrise mai jos. `null` doar pentru snapshot-uri
     *                                    vechi, fără actor rezolvabil.
     * @param  string  $ipAddress  Idem, capturat la dispatch (`App\Actions\Bulk\
     *                             DispatchBulkOperationAction`) — coloana nu e nullabilă.
     * @param  string  $userAgent  Idem.
     */
    public function __construct(
        public string $tenantId,
        public string $bulkOperationId,
        public string $resourceType,
        public string $action,
        public array $ids,
        public array $payload,
        public int $chunk,
        public ?string $actorUserId = null,
        public string $ipAddress = '0.0.0.0',
        public string $userAgent = 'system',
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

        // `insertOrIgnore` ocolește evenimentele Eloquent (ca în
        // `LocksInventoryLevels::ensureLevelsExist()`), deci `id`/`tenant_id`/`created_at`
        // se completează explicit. Verificat: rulează ÎN ACEEAȘI tranzacție cu `apply()`
        // de mai jos — `ApplyTenantContextToJob` deschide UNA singură pentru tot
        // `handle()` (`TenantContext::run()` → `DB::transaction()`), deci fie ambele se
        // comit împreună, fie niciuna.
        $inserted = BulkOperationChunk::query()->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()),
            'tenant_id' => TenantScope::requireCurrentTenantId(),
            'bulk_operation_id' => $this->bulkOperationId,
            'chunk' => $this->chunk,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            return;
        }

        $resource = BulkWritableResources::resolve($this->resourceType);
        $executor = BulkChunkActions::resolve($this->action);
        $modelClass = $resource->modelClass();
        $keyName = $resource->newQuery()->getModel()->getKeyName();

        // Lotul E (§13.2 pct. 3/5, §13.3) — instantaneul DINAINTE, citit ÎN ACEEAȘI
        // tranzacție ca `insertOrIgnore()` de mai sus și ca `apply()` de mai jos
        // (`ApplyTenantContextToJob` deschide UNA singură pentru tot `handle()`): un
        // `UPDATE` în masă nu declanșează `updated` pe Eloquent, deci fără acest instant-
        // aneu n-ar exista nicio valoare „veche" de comparat — vezi `App\Support\Activity\
        // BulkChunkActivityRecorder`.
        $before = $resource->newQuery()->whereIn($keyName, $this->ids)->get()->keyBy($keyName);

        $executor->apply($resource, $this->ids, $this->payload);

        $after = $resource->newQuery()->whereIn($keyName, $this->ids)->get()->keyBy($keyName);

        app(BulkChunkActivityRecorder::class)->record(
            $modelClass,
            $this->bulkOperationId,
            $this->actorUserId,
            $this->ipAddress,
            $this->userAgent,
            $before,
            $after,
        );
    }
}
