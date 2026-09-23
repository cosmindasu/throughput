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
 *     bazează STRICT pe el (sursa a doua de adevăr, nu singura). Starea locală e, la rândul
 *     ei, protejată de livrarea Stripe în altă ordine: un eveniment mai vechi decât ultimul
 *     aplicat e ignorat (`subscriptions.last_stripe_event_at`, audit 2026-09-23).
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
 *  - **Jetoanele Sanctum** (`personal_access_tokens`) — fără `tenant_id` și fără FK, tenantul
 *    fiind codat doar în `abilities`; legate prin `api_tokens.token_hash`, vezi
 *    `deleteSanctumTokenRows()`.
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
        $cutoff = self::cutoff();

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

    private static function cutoff(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->subDays((int) config('throughput.limits.tenant_purge_retention_days'));
    }

    /**
     * Ambele trepte ale criteriului (vezi docblock-ul clasei). A doua e citită prin
     * `Subscription::valid()` — logica PROPRIE a pachetului (inclusiv
     * `Cashier::keepPastDueSubscriptionsActive()` din `AppServiceProvider`), nu reprodusă în
     * SQL brut, care ar putea diverge tăcut la următorul upgrade Cashier.
     */
    private function isEligible(Tenant $tenant): bool
    {
        if ($tenant->subscription_canceled_at === null
            || CarbonImmutable::parse($tenant->subscription_canceled_at)->greaterThan(self::cutoff())) {
            return false;
        }

        return ! $tenant->subscriptions()->get()->contains(fn ($subscription) => $subscription->valid());
    }

    private function purgeIfEligible(Tenant $tenant): void
    {
        // Filtru ieftin, fără blocare; verificarea autoritară se repetă sub blocare, mai jos.
        if (! $this->isEligible($tenant)) {
            return;
        }

        $this->purgeTenant($tenant->getKey());
    }

    /**
     * O SINGURĂ tranzacție, cu rândul `tenants` blocat de la început (audit GDPR-01,
     * 2026-09-23, P3 — două curse între verificare și ștergere):
     *
     *  - eligibilitatea se RE-VERIFICĂ sub blocare: o reactivare procesată între filtrul din
     *    `purgeIfEligible()` și ștergere (webhook-ul scrie `tenants.subscription_canceled_at`,
     *    deci așteaptă blocarea) e văzută, nu ignorată;
     *  - lista de membri se citește DUPĂ blocare: acceptarea concurentă a unei invitații
     *    verifică FK-ul spre `tenants` (`FOR KEY SHARE`), deci așteaptă și ea — niciun membru
     *    nou nu scapă de evaluarea `OrphanUserCleanup`.
     *
     * `FOR UPDATE`, nu `FOR NO KEY UPDATE` (`.ai/rules/tenancy.md`): rândul chiar se ȘTERGE.
     * Fișierele se șterg în interiorul tranzacției: o eroare de disc face rollback la rânduri,
     * iar rularea următoare reia totul (ștergerea unui folder deja gol e un no-op).
     */
    private function purgeTenant(string $tenantId): void
    {
        $memberUserIds = [];

        $purged = DB::transaction(function () use ($tenantId, &$memberUserIds): bool {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->first();

            if ($tenant === null || ! $this->isEligible($tenant)) {
                return false;
            }

            // Sub RLS: `memberships` și `api_tokens` se văd doar în contextul tenantului.
            [$memberUserIds, $tokenHashes] = TenantContext::run($tenant, fn (): array => [
                DB::table('memberships')->where('tenant_id', $tenantId)->pluck('user_id')->all(),
                DB::table('api_tokens')->where('tenant_id', $tenantId)->pluck('token_hash')->all(),
            ]);

            $this->deleteTenantFiles($tenantId);
            $this->deleteSanctumTokenRows($tokenHashes);
            $this->deleteCashierSubscriptionRows($tenantId);
            $this->deletePermissionRows($tenantId);

            // Cascadă spre cele 32 de tabele `->cascadeOnDelete()` — vezi docblock-ul clasei.
            DB::table('tenants')->where('id', $tenantId)->delete();

            return true;
        });

        if (! $purged) {
            return;
        }

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
     * `personal_access_tokens` (Sanctum) — secretul hash-uit al fiecărui jeton API, cu
     * tenantul codat doar în `abilities` (JSON), fără `tenant_id` și fără FK: nici cascada,
     * nici un inventar pe `information_schema` nu-l văd. Legătura e `api_tokens.token_hash`
     * (identic cu `personal_access_tokens.token`, vezi `ApiToken`), citită ÎNAINTE ca
     * cascada să șteargă `api_tokens`. Audit GDPR-01, 2026-09-23 (P1).
     *
     * @param  list<string>  $tokenHashes
     */
    private function deleteSanctumTokenRows(array $tokenHashes): void
    {
        if ($tokenHashes !== []) {
            DB::table('personal_access_tokens')->whereIn('token', $tokenHashes)->delete();
        }
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
