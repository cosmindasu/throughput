<?php

namespace App\Listeners\Activity;

use App\Events\Activity\ModelWasRecorded;
use App\Models\ActivityLog;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * ADR-007 — „un queued listener scrie asincron rândul în `activity_log`." Scrierea NU se
 * întâmplă în firul cererii (research §8 — recomandare explicită de logging asincron).
 *
 * De ce `TenantContext::run()` DIRECT în `handle()`, nu `App\Jobs\Middleware\
 * ApplyTenantContextToJob` declarat prin `middleware()`: acel middleware citește
 * `$job->tenantId` de pe INSTANȚA jobului de coadă — pentru un listener, jobul de coadă e
 * `Illuminate\Events\CallQueuedListener` (fabricat de framework), care n-are o proprietate
 * `tenantId`; ar citi `null` și `TenantContext::run(null, ...)` ar arunca. Apelul direct,
 * cu `$event->tenantId` (scalar, capturat la dispatch — vezi docblock-ul evenimentului),
 * e mecanismul echivalent, fără să depindă de o presupunere despre forma internă a
 * listener-ului de coadă.
 *
 * O reîncercare (job redelivrat după un crash între commit și ack) SCRIE A DOUA OARĂ un
 * rând de jurnal identic — acceptat, deliberat: `activity_log` e append-only prin
 * construcție (§17, `App\Concerns\AppendOnly`), n-are un marcaj „ultima modificare" pe care
 * să-l verifice ca `App\Jobs\Bulk\ProcessBulkChunkJob` (acolo idempotența contează pentru
 * EFECTUL asupra rândului de business, nu doar pentru jurnal). Un rând de jurnal duplicat,
 * foarte rar, e un cost de audit acceptabil față de complexitatea unui marcaj suplimentar
 * pe o coadă unde retry-urile sunt oricum excepționale (`tries` mic, mai jos).
 */
final class WriteActivityLogEntry implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 30;

    public function handle(ModelWasRecorded $event): void
    {
        TenantContext::run($event->tenantId, function () use ($event): void {
            ActivityLog::query()->create([
                'user_id' => $event->userId,
                'action' => $event->action,
                'auditable_type' => $event->auditableType,
                'auditable_id' => $event->auditableId,
                'old_values' => $event->oldValues,
                'new_values' => $event->newValues,
                'ip_address' => $event->ipAddress,
                'user_agent' => $event->userAgent,
            ]);
        });
    }
}
