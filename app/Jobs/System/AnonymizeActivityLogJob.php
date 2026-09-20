<?php

namespace App\Jobs\System;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * FR-AUD-01, specs.md §17.2 — retenția jurnalului de activitate: „rândurile mai vechi de
 * 36 de luni au câmpurile identificabile din `old_values`/`new_values` înlocuite cu un
 * placeholder (`[anonymized]`) ... NU se șterge rândul integral."
 *
 * Job LUNAR, de SISTEM (`.ai/rules/tenancy.md`, ADR-014 pct. 4): fără tenant propriu,
 * iterează tenanții explicit, cu o tranzacție și un context per tenant, structurat ca
 * `App\Jobs\System\PruneSentEmailsJob` (același principiu de retenție GDPR, §20.5).
 *
 * **Scop — doar entitățile cu date PERSONALE**, per litera FR-AUD-01 („contacte, useri"):
 * `Contact` și `User`. Account/Deal/Order/Product/Variant sunt date de BUSINESS, nu ale
 * unei persoane fizice — anonimizarea lor n-ar avea sens GDPR și ar distruge fără motiv
 * un istoric util („cine a schimbat prețul variantei X", US-AUD-01). Astăzi, doar rândurile
 * `Contact` sunt scrise de mecanismul acestui lot (`App\Providers\ActivityLogServiceProvider`
 * NU observă `User` — vezi docblock-ul provider-ului); `User` rămâne în listă pentru orice
 * scriere viitoare (alt lot) care ar loga modificări de profil.
 *
 * **De ce `UPDATE` prin `DB::table()`, nu `App\Models\ActivityLog::query()->update()`**:
 * identic cu motivul din `PruneSentEmailsJob` — un `Builder::update()` în masă NU
 * declanșează evenimente Eloquent (deci n-ar lovi oricum garda `App\Concerns\AppendOnly`,
 * care oprește doar `updating`/`deleting` PER INSTANȚĂ), dar `DB::table()` evită orice
 * ambiguitate legată de global scope-ul de tenant și de cast-ul `array` pe coloane `jsonb`
 * (un `UPDATE` SQL brut scrie JSON direct, calculat de PostgreSQL însuși — vezi mai jos).
 *
 * **De ce transformarea rulează ÎN SQL (`jsonb_object_agg`/`jsonb_each`), nu în PHP**: câte
 * o transformare per cheie JSON, per rând, ar cere fie citirea integrală a rândurilor în
 * PHP (cost de memorie pe un tenant vechi, cu istoric mare), fie N interogări individuale.
 * Un singur `UPDATE ... FROM (SELECT ...)` per chunk de id-uri face ambele citiri și
 * scrierea într-un singur round-trip. Cheile JSON (numele câmpurilor modificate) se
 * PĂSTREAZĂ — doar valorile devin `"[anonymized]"` — exact „păstrând structura rândului...
 * dar eliminând conținutul personal" din §17.2: se vede CE câmp s-a schimbat (ex: „email"),
 * nu CE valoare a avut.
 *
 * Idempotent prin CONVERGENȚĂ, nu prin marcaj: re-aplicarea transformării unui rând deja
 * anonimizat produce exact același rezultat (`"[anonymized]"` → `"[anonymized]"`), deci o
 * rulare lunară care se suprapune parțial cu luna anterioară (tenant cu ceas decalat, job
 * reluat după un eșec) nu dublează nimic și nu are nevoie de un flag separat. Clauza
 * `IS DISTINCT FROM` din `UPDATE` de mai jos e doar o optimizare (sare peste rândurile deja
 * convergente), nu condiția de corectitudine.
 */
class AnonymizeActivityLogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    private const CHUNK_SIZE = 500;

    private const PLACEHOLDER = '[anonymized]';

    public static function retentionMonths(): int
    {
        return (int) config('throughput.limits.activity_log_retention_months');
    }

    /** @return list<class-string> */
    private static function personalAuditableTypes(): array
    {
        return [Contact::class, User::class];
    }

    public function handle(): void
    {
        $cutoff = CarbonImmutable::now('UTC')->subMonths(self::retentionMonths());

        Tenant::query()->eachById(function (Tenant $tenant) use ($cutoff): void {
            TenantContext::run($tenant, function () use ($cutoff): void {
                $this->anonymizeCurrentTenant($cutoff);
            });
        });
    }

    private function anonymizeCurrentTenant(CarbonImmutable $cutoff): void
    {
        DB::table('activity_log')
            ->whereIn('auditable_type', self::personalAuditableTypes())
            ->where('created_at', '<=', $cutoff)
            ->where(function ($query): void {
                $query->whereNotNull('old_values')->orWhereNotNull('new_values');
            })
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows): void {
                $this->anonymizeChunk($rows->pluck('id')->all());
            }, 'id');
    }

    /** @param  list<string>  $ids */
    private function anonymizeChunk(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // `jsonb_each` cere un OBJECT jsonb — `old_values`/`new_values` sunt mereu obiecte
        // „nume câmp => valoare" (niciodată liste), per convenția de scriere din
        // `App\Support\Activity\ChangedAttributes`, deci fără riscul erorii Postgres
        // „cannot call jsonb_each on a non-object".
        DB::statement(
            <<<SQL
                UPDATE activity_log a
                SET old_values = t.new_old,
                    new_values = t.new_new
                FROM (
                    SELECT
                        id,
                        CASE WHEN old_values IS NULL THEN NULL
                             ELSE (SELECT jsonb_object_agg(e.key, to_jsonb(?::text)) FROM jsonb_each(old_values) AS e)
                        END AS new_old,
                        CASE WHEN new_values IS NULL THEN NULL
                             ELSE (SELECT jsonb_object_agg(e.key, to_jsonb(?::text)) FROM jsonb_each(new_values) AS e)
                        END AS new_new
                    FROM activity_log
                    WHERE id IN ({$placeholders})
                ) t
                WHERE a.id = t.id
                  AND (a.old_values IS DISTINCT FROM t.new_old OR a.new_values IS DISTINCT FROM t.new_new)
                SQL,
            [self::PLACEHOLDER, self::PLACEHOLDER, ...$ids],
        );
    }
}
