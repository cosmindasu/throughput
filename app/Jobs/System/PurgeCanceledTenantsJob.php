<?php

namespace App\Jobs\System;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Members\OrphanUserCleanup;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * GDPR-01, ADR-012, BR-BILL-05 (specs.md §20.5) — la 30 de zile de la anularea definitivă a
 * abonamentului, tenantul se ȘTERGE INTEGRAL, nu doar se anonimizează. Decizie explicită a
 * proprietarului (vezi secțiunea „Implementare" din ADR-012): valoarea implicită scrisă deja
 * în specs §20.5 pentru acest demo — „fără retenție suplimentară dincolo de cele 30 de zile"
 * — înlocuiește mențiunea din ADR-012 despre anonimizarea facturilor. Stripe rămâne
 * NEATINS (§20.5): clientul și istoricul de facturare pe partea Stripe supraviețuiesc — se
 * șterge doar copia locală (`tenants`, `subscriptions`/`subscription_items` Cashier).
 *
 * Job de SISTEM (`.ai/rules/tenancy.md`, „Două familii de joburi"): fără tenant propriu,
 * iterează tenanții eligibili explicit, UN TENANT PE ITERAȚIE — eșecul unuia (o referință
 * FK neprevăzută, o eroare de disc) NU oprește purjarea celorlalți; excepția se raportează
 * (`report()`) și bucla trece mai departe, la fel cum `Bus::batch()->allowFailures()`
 * izolează eșecurile într-un batch, dar fără mecanismul de batch — aici e o simplă buclă
 * `eachById()` cu propriul `try`/`catch`.
 *
 * CRITERIUL DE ELIGIBILITATE, în două trepte — niciuna singură nu ar fi suficientă:
 *
 *  1. `subscription_canceled_at <= now() - 30 zile` (coloana, verificată direct în SQL,
 *     la fel ca restul joburilor de retenție din listă).
 *  2. Tenantul NU are, ACUM, niciun abonament Cashier valid — `Subscription::valid()`
 *     (activ SAU în trial SAU în perioadă de grație), citit din STAREA reală a pachetului,
 *     nu doar din coloana locală. Treapta asta există EXPLICIT pentru bug-ul de reactivare
 *     (`App\Jobs\Webhooks\ProcessStripeWebhookJob`, secțiunea „Implementare" din ADR-012):
 *     portalul Stripe poate readuce un abonament la `active` fără să golească
 *     `subscription_canceled_at` — fixul de acolo rezolvă cazul curent, dar acest job nu se
 *     bazează STRICT pe el (sursa a doua de adevăr, nu singura).
 *
 * CE SE ȘTERGE, pe lângă rândul `tenants` (care cascadează spre cele 32 de tabele cu
 * `->constrained('tenants')->cascadeOnDelete()` — `memberships`, `accounts`, `deals`,
 * `orders`, `invoices`, `payments`, `activity_log`, `bulk_operations`, ... — verificat
 * EMPIRIC de `PurgeCanceledTenantsJobTest::test_no_tenant_scoped_table_keeps_a_row_after_purge()`,
 * pe schema REALĂ via `information_schema.columns`, nu pe o listă din memorie):
 *
 *  - **Tabelele Cashier** (`subscriptions`, `subscription_items`) — NU au FK/cascadă spre
 *    `tenants`: `subscriptions.user_id` (care ține de fapt un `tenants.id`, vezi docblock-ul
 *    relației `Tenant::subscriptions()`) e un `ulid` simplu, fără `->constrained()`, iar
 *    `subscription_items.subscription_id` e un `foreignId()` FĂRĂ `->constrained()` deloc —
 *    verificat direct în migrațiile pachetului adaptate de acest proiect. Șterse explicit,
 *    în această ordine (copil înainte de părinte, deși absența FK-ului n-ar fi cerut-o).
 *  - **Tabelele `spatie/laravel-permission` cu scopare pe tenant** (`roles`,
 *    `model_has_roles`, `model_has_permissions` — `team_foreign_key` = `tenant_id`,
 *    `config/permission.php`) — DELIBERAT fără RLS și fără FK spre `tenants`
 *    (`2026_09_12_100011_create_permission_tables.php`: „`PermissionRegistrar` încarcă
 *    întreg catalogul... sub RLS, warmup-ul ar întoarce zero rânduri"), deci o cascadă pe
 *    `tenants` nu le-ar fi atins niciodată. Excluse deliberat din
 *    `ModelTenantScopeCoverageTest`/`IsolationTest` (nu sunt modele Eloquent ale acestui
 *    proiect) — dar tot au `tenant_id`, deci tot trebuie curățate la purjare. `permissions`
 *    (catalogul global, fără `team_foreign_key`) rămâne neatins.
 *  - **Fișierele de pe disc**, pe discul `local`, sub rădăcina `{tenantId}` a fiecărei
 *    zone care scrie per-tenant (grep pe `Storage::`/`disk(` în `app/`): `exports/`
 *    (`ExportListJob`), `gdpr-exports/` (`App\Actions\Gdpr\DataExportPaths` — arhivele
 *    GDPR au propria rădăcină, separată de `exports/`, ca `PruneExpiredExportsJob` să nu le
 *    calce), `imports/` (`ImportFilePath`), `invoices/` (`GenerateInvoicePdfJob`),
 *    `reports/` (`GenerateReportJob`). Datele personale dintr-un fișier contează la fel ca
 *    cele dintr-un rând de bază de date.
 *
 * CE NU SE ȘTERGE: userii care mai au ORICE membership în alt tenant, activ SAU dezactivat
 * (ADR-011 nu șterge niciodată fizic un membership) — verificat cross-tenant prin
 * `App\Support\Members\OrphanUserCleanup` (partajat, NESCHIMBAT, cu `PruneExpiredInvitationsJob`,
 * GDPR-09). Un user orfan cu o referință FK reziduală (`23503`) rămâne păstrat, cu un
 * avertisment fără PII — restul purjării ACESTUI tenant continuă (userul e evaluat DUPĂ ce
 * tenantul a fost deja șters).
 *
 * DEMO_MODE — deliberat FĂRĂ nicio gardă în cod. Niciun seeder demo
 * (`database/seeders/Demo/*`) nu scrie `subscription_canceled_at`: tabela `subscriptions`
 * nu e populată deloc de setul de date demo. Chiar dacă un vizitator ar anula un abonament
 * de test în timpul zilei, `demo:reset` rulează `migrate:fresh` în fiecare noapte (03:00
 * UTC, `App\Console\Commands\DemoReset`) — NICIUN rând `tenants` supraviețuiește mai mult
 * de ~24h cât `DEMO_MODE=true`, deci coloana nu poate ajunge NICIODATĂ la pragul de 30 de
 * zile cât timp demo-ul rulează normal. Dacă un lot viitor ar vrea să semene un tenant demo
 * cu starea „canceled" pentru ecranul de billing, data semănată ar trebui să fie RECENTĂ
 * (în fereastra de grație), din chiar motivul pentru care există seed-ul — un tenant demo cu
 * o dată VECHE de 31+ zile n-ar mai fi vizibil să demonstreze nimic. Dacă `DEMO_MODE` e
 * oprit definitiv (site-ul nu mai e demo-ul public), purjarea tenanților rămași e
 * comportamentul CORECT, nu o gaură de acoperit.
 *
 * IDEMPOTENT: a doua rulare nu (mai) găsește tenantul (rândul `tenants` a dispărut, deci
 * criteriul WHERE nu-l mai selectează), iar ștergerea fișierelor pe un folder deja golit e
 * un no-op (`filesystems.php`: `throw => false`).
 */
class PurgeCanceledTenantsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** Discul pe care scriu TOATE zonele per-tenant enumerate mai sus — vezi docblock-ul clasei. */
    private const DISK = 'local';

    /** @var list<string> rădăcinile de pe disc care păstrează un subfolder `{tenantId}`. */
    private const FILE_ROOTS = ['exports', 'gdpr-exports', 'imports', 'invoices', 'reports'];

    public function handle(): void
    {
        $cutoff = CarbonImmutable::now('UTC')->subDays(self::retentionDays());

        Tenant::query()
            ->whereNotNull('subscription_canceled_at')
            ->where('subscription_canceled_at', '<=', $cutoff)
            ->eachById(function (Tenant $tenant): void {
                try {
                    $this->purgeIfEligible($tenant);
                } catch (Throwable $e) {
                    report($e);

                    Log::error('PurgeCanceledTenantsJob: purge failed for one tenant — the rest of the batch continues.', [
                        'tenant_id' => $tenant->getKey(),
                    ]);
                }
            });
    }

    private static function retentionDays(): int
    {
        return (int) config('throughput.limits.tenant_purge_retention_days');
    }

    private function purgeIfEligible(Tenant $tenant): void
    {
        // A doua treaptă a criteriului — vezi docblock-ul clasei. Verificată AICI, nu în
        // interogarea SQL de mai sus: `Subscription::valid()` combină trei coloane
        // (`stripe_status`, `ends_at`, `trial_ends_at`) prin logica PROPRIE a pachetului
        // (inclusiv `Cashier::keepPastDueSubscriptionsActive()`, setat în
        // `AppServiceProvider`) — reprodus în SQL brut ar putea diverge tăcut de pachet la
        // următorul upgrade Cashier.
        if ($this->hasValidSubscription($tenant)) {
            return;
        }

        $this->purgeTenant($tenant);
    }

    private function hasValidSubscription(Tenant $tenant): bool
    {
        return $tenant->subscriptions()->get()->contains(fn ($subscription) => $subscription->valid());
    }

    private function purgeTenant(Tenant $tenant): void
    {
        $tenantId = $tenant->getKey();

        // Cine ar putea rămâne orfan: citit ÎN CONTEXTUL tenantului (politica RLS
        // `membership_visibility` a lui `memberships` face vizibile toate rândurile lui,
        // indiferent de user), ÎNAINTE ca ștergerea de mai jos să care rândul `memberships`
        // odată cu cascada de pe `tenants` — după aceea, „cine avea o membership aici" nu
        // mai e recuperabil din bază.
        $memberUserIds = TenantContext::run(
            $tenant,
            fn () => DB::table('memberships')->where('tenant_id', $tenantId)->pluck('user_id')->all(),
        );

        // Fișierele de pe disc — ÎN AFARA oricărei tranzacții Postgres (nu sunt
        // tranzacționale) și ÎNAINTEA ștergerii din bază: dacă ștergerea unui fișier ar
        // eșua neașteptat, excepția oprește purjarea ACESTUI tenant (prinsă de bucla din
        // `handle()`) înainte să dispară vreun rând — o rulare viitoare reia purjarea de
        // la zero, idempotent (fișierele deja șterse sunt no-op-uri).
        $this->deleteTenantFiles($tenantId);

        DB::transaction(function () use ($tenantId): void {
            $this->deleteCashierSubscriptionRows($tenantId);
            $this->deletePermissionRows($tenantId);

            // Cascadă spre cele 32 de tabele `->cascadeOnDelete()` — vezi docblock-ul clasei.
            DB::table('tenants')->where('id', $tenantId)->delete();
        });

        $deletedMembers = 0;
        foreach ($memberUserIds as $userId) {
            if (OrphanUserCleanup::deleteIfOrphan($userId, self::class)) {
                $deletedMembers++;
            }
        }

        // Fără PII: doar id-ul tenantului (opac, nu identifică o persoană) și numărători.
        Log::info('PurgeCanceledTenantsJob: tenant purged.', [
            'tenant_id' => $tenantId,
            'members_evaluated' => count($memberUserIds),
            'members_deleted' => $deletedMembers,
        ]);
    }

    /**
     * `subscriptions`/`subscription_items` (Cashier) — vezi docblock-ul clasei pentru DE CE
     * n-are nicio cascadă pe care ștergerea lui `tenants` s-ar putea baza.
     */
    private function deleteCashierSubscriptionRows(string $tenantId): void
    {
        $subscriptionIds = DB::table('subscriptions')->where('user_id', $tenantId)->pluck('id');

        if ($subscriptionIds->isNotEmpty()) {
            DB::table('subscription_items')->whereIn('subscription_id', $subscriptionIds)->delete();
        }

        DB::table('subscriptions')->where('user_id', $tenantId)->delete();
    }

    /**
     * `roles`/`model_has_roles`/`model_has_permissions` (Spatie, `team_foreign_key` =
     * `tenant_id`) — vezi docblock-ul clasei pentru DE CE nu sunt atinse de cascada de pe
     * `tenants`. Cache-ul pachetului se golește după, ca `RoleAndPermissionSeeder`: altfel
     * un proces de coadă de lungă durată (Horizon) ar ține în memorie roluri ale unui
     * tenant tocmai șters.
     */
    private function deletePermissionRows(string $tenantId): void
    {
        DB::table('model_has_roles')->where('tenant_id', $tenantId)->delete();
        DB::table('model_has_permissions')->where('tenant_id', $tenantId)->delete();
        DB::table('roles')->where('tenant_id', $tenantId)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Vezi docblock-ul clasei pentru enumerarea zonelor și de ce toate stau pe discul `local`. */
    private function deleteTenantFiles(string $tenantId): void
    {
        $disk = Storage::disk(self::DISK);

        foreach (self::FILE_ROOTS as $root) {
            $disk->deleteDirectory("{$root}/{$tenantId}");
        }
    }
}
