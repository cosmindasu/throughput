<?php

namespace App\Jobs\System;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Activity\ActivityLogAnonymizer;
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
 * **De ce doar selecția SQL stă aici**: mecanica propriu-zisă de mascare (`UPDATE` per
 * chunk, `jsonb_object_agg`/`jsonb_each`, convergență, `IS DISTINCT FROM`) a fost extrasă
 * în `App\Support\Activity\ActivityLogAnonymizer` (GDPR-02, audit 2026-09-23,
 * `docs/reviews/2026-09-23_audit/08-gdpr.md`) — acest job construiește DOAR criteriul de
 * selecție („personal + mai vechi de N luni") și îl pasează helper-ului comun, care e
 * folosit identic din `App\Support\Contacts\ContactErasure` (mascare la MOMENTUL
 * ștergerii unui contact, nu doar la 36 de luni). Docblock-ul complet al mecanicii SQL
 * stă acolo, nu duplicat aici.
 */
class AnonymizeActivityLogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

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
        ActivityLogAnonymizer::anonymize(
            DB::table('activity_log')
                ->whereIn('auditable_type', self::personalAuditableTypes())
                ->where('created_at', '<=', $cutoff)
                ->where(function ($query): void {
                    $query->whereNotNull('old_values')->orWhereNotNull('new_values');
                }),
        );
    }
}
