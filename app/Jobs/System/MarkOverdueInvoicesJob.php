<?php

namespace App\Jobs\System;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * BR-BILL-02 — job zilnic: `sent -> overdue` pentru orice factură cu `due_date` trecut
 * și `balance_due > 0`. Job de SISTEM (`.ai/rules/tenancy.md`, ADR-014 pct. 4, ca
 * `DispatchScheduledReportsJob`/`PruneExpiredExportsJob`): fără tenant propriu, iterează
 * tenanții explicit, cu un context per tenant, pe conexiunea aplicației — NICIODATĂ pe
 * cea cu BYPASSRLS (rezervată exclusiv `artisan migrate`).
 *
 * Spre deosebire de `DispatchScheduledReportsJob` (care verifică „e deja o rulare în
 * fereastră" — un citește-apoi-scrie care ARE nevoie de `->lock('for no key update')` pe
 * părinte, altfel două execuții concurente inserează amândouă), aici nu există nicio
 * cursă de citește-apoi-inserează: fiecare tenant primește un singur `UPDATE ... WHERE`
 * condiționat, executat ATOMIC de Postgres. O a doua rulare concurentă (redeploy, tick
 * suprapus) pur și simplu nu mai găsește rânduri `status = sent` peste cele deja trecute
 * la `overdue` de prima — idempotent prin construcție, fără nicio blocare de rând.
 *
 * TODO(lot-E): scrierea în `activity_log` pentru această tranziție de sistem
 * (`user_id = null`, BR-BILL-02) e instrumentarea live a jurnalului de audit (ADR-007,
 * observers pe modelele de business), construită o singură dată, pentru tot tenantul, de
 * lotul E din această fază — plan-implementare.md §11, secțiunea „Jurnal de activitate".
 * Nu duplic acel mecanism aici cu un scris punctual: ar însemna două căi care scriu
 * `activity_log`, exact genul de duplicare pe care ADR-007 îl exclude explicit. După ce
 * lotul E adaugă observer-ul pe `Invoice`, tranziția de mai jos (`Invoice::update()`)
 * va apărea automat în jurnal, fără nicio modificare aici.
 */
class MarkOverdueInvoicesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        Tenant::query()->eachById(function (Tenant $tenant): void {
            TenantContext::run($tenant, function (): void {
                Invoice::query()
                    ->where('status', Invoice::STATUS_SENT)
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', now()->toDateString())
                    ->where('balance_due', '>', 0)
                    ->update(['status' => Invoice::STATUS_OVERDUE]);
            });
        });
    }
}
