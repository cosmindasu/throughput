<?php

namespace App\Jobs\System;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * BR-DEMO-02, specs.md §22.3 — retenția jurnalului „Sent Emails": nu trebuie să crească
 * nemărginit, mai ales că fiecare rând conține conținutul complet al unui email.
 *
 * În practică, cât timp `DEMO_MODE=true`, `demo:reset` (FR-DEMO-03, §22.1) rulează
 * `migrate:fresh` în fiecare noapte la 03:00 UTC — golește ȘI `sent_emails`, ca orice altă
 * tabelă, indiferent de acest job (`App\Console\Commands\DemoReset`). Acest job rămâne
 * totuși un plasă de siguranță utilă: `ResetDemoDataJob` are `tries = 1` (un reset eșuat nu
 * se reia automat, deliberat), deci o noapte cu reset picat ar lăsa jurnalul să crească
 * nesupravegheat până la următoarea rulare reușită. Cu `DEMO_MODE=false` (vezi
 * `App\Mail\Transport\DemoInterceptingTransport`), tabela nu primește niciun rând nou, deci
 * jobul e un no-op ieftin — nu are nevoie de `when()`, la fel ca `PruneExpiredExportsJob`.
 *
 * Retenția vine din `throughput.limits.sent_email_retention_days`, alături de
 * `export_retention_days` și din același motiv (§20.5): jurnalul păstrează conținutul
 * complet al mesajelor, deci sunt date personale, iar durata lor de viață se reglează
 * dintr-un singur loc, fără redeploy de cod.
 *
 * Job de SISTEM (`.ai/rules/tenancy.md`, ADR-014 pct. 4): fără tenant propriu, iterează
 * tenanții explicit, cu un context per tenant, pe conexiunea aplicației (NICIODATĂ cea cu
 * BYPASSRLS). Ștergerea e prin query builder BRUT (`DB::table()`), NU prin
 * `App\Models\SentEmail` — un `Model::query()->where(...)->delete()` NU declanșează
 * evenimente per rând (deci n-ar lovi oricum garda `App\Concerns\AppendOnly`), dar
 * `DB::table()` evită complet orice ambiguitate legată de global scope-ul de tenant pe
 * trecerea FĂRĂ context, de mai jos.
 *
 * A doua trecere (rândurile FĂRĂ tenant — recuperarea parolei, FR-PUB-05) rulează în afara
 * oricărui `TenantContext::run()`: politica RLS a tabelei (vezi migrația `sent_emails`)
 * tratează „fără context" (NULL SAU '') ca ramură separată, singura sub care acele rânduri
 * devin vizibile — cu un tenant activ în context, ele rămân invizibile, ca de obicei.
 */
class PruneSentEmailsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public static function retentionDays(): int
    {
        return (int) config('throughput.limits.sent_email_retention_days');
    }

    public function handle(): void
    {
        $cutoff = now()->subDays(self::retentionDays());

        Tenant::query()->eachById(function (Tenant $tenant) use ($cutoff): void {
            TenantContext::run($tenant, function () use ($cutoff): void {
                DB::table('sent_emails')->where('created_at', '<', $cutoff)->delete();
            });
        });

        // Rândurile fără tenant (FR-PUB-05) — vezi docblock-ul clasei.
        DB::table('sent_emails')->where('created_at', '<', $cutoff)->delete();
    }
}
