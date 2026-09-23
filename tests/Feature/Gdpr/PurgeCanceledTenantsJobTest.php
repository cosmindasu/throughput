<?php

namespace Tests\Feature\Gdpr;

use App\Jobs\System\PurgeCanceledTenantsJob;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesInvoices;
use Tests\Concerns\CreatesOrders;
use Tests\Concerns\CreatesPipelines;
use Tests\TestCase;

/**
 * GDPR-01, ADR-012, BR-BILL-05 (specs.md §20.5).
 *
 * Fișierele scrise pe disc folosesc discul `local` REAL (`storage/app/private`), NU
 * `Storage::fake()`: mai mulți agenți rulează suita în paralel, pe același checkout —
 * un disc fals (`storage/framework/testing/disks/...`) e o cale PARTAJATĂ pe filesystem,
 * nu izolată per proces, exact capcana deja lovită („Storage::fake comun ⇒ GDPR pică
 * fals"). Fiecare tenant de test are un ULID propriu, generat aleator de `makeTenant()`
 * via Cashier/HasUlids — coliziunea cu alt agent e practic imposibilă, iar `tearDown()`
 * curăță orice folder rămas, indiferent de rezultatul testului.
 */
class PurgeCanceledTenantsJobTest extends TestCase
{
    use CreatesInvoices, CreatesOrders, CreatesPipelines;

    /** @var list<string> */
    private array $tenantIdsWithFiles = [];

    protected function tearDown(): void
    {
        $disk = Storage::disk('local');

        foreach ($this->tenantIdsWithFiles as $tenantId) {
            foreach (['exports', 'gdpr-exports', 'imports', 'invoices', 'reports'] as $root) {
                $disk->deleteDirectory("{$root}/{$tenantId}");
            }
        }

        parent::tearDown();
    }

    public function test_a_tenant_canceled_past_the_threshold_with_no_active_subscription_is_purged_completely(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'owner@example.test', Permissions::OWNER);
        $tenantId = $tenant->getKey();

        $this->seedFullDataset($tenant, $owner);
        // Abonament Cashier ANULAT, complet încheiat (nu în perioadă de grație) — eligibil.
        $this->insertCashierSubscription($tenantId, 'canceled', now()->subDays(31));
        $this->writeTenantFiles($tenantId);

        // Tenantul MARTOR — trebuie să rămână complet neatins de rularea de mai jos.
        $witness = $this->makeTenant('cascade', 'Cascade Metal Works');
        $witnessOwner = $this->makeMember($witness, 'witness-owner@example.test', Permissions::OWNER);
        TenantContext::run($witness, function () use ($witnessOwner): void {
            $account = new Account(['name' => 'Cascade Secret Customer Inc.']);
            $account->created_by = $witnessOwner->getKey();
            $account->save();

            ApiToken::issue($witnessOwner, 'Witness token', ['*']);
        });

        $tenant->forceFill(['subscription_canceled_at' => now()->subDays(31)])->save();
        $this->clearDatabaseTenantContext();

        (new PurgeCanceledTenantsJob)->handle();

        $this->assertNull(Tenant::query()->find($tenantId), 'The tenant row itself must be gone.');
        $this->assertNull(User::query()->find($owner->getKey()), 'The sole owner, left with no membership anywhere, must be deleted too.');
        $this->assertNoTenantScopedRowsRemain($tenantId);
        $this->assertSame(0, DB::table('subscriptions')->where('user_id', $tenantId)->count(), 'Cashier subscriptions have no FK to tenants — must be deleted explicitly.');
        $this->assertSame(0, DB::table('subscription_items')->count(), 'subscription_items has no FK at all — orphaned rows must not survive.');
        $this->assertSame(
            0,
            DB::table('personal_access_tokens')->where('abilities', 'like', '%tenant:'.$tenantId.'%')->count(),
            'The Sanctum row (hashed secret, no tenant_id column) must go with the tenant.',
        );

        foreach (['exports', 'gdpr-exports', 'imports', 'invoices', 'reports'] as $root) {
            $this->assertFalse(Storage::disk('local')->exists("{$root}/{$tenantId}"), "Files under {$root}/{$tenantId} must be gone.");
        }

        // Tenantul martor: rândul, membership-ul și datele de business supraviețuiesc.
        $this->assertNotNull(Tenant::query()->find($witness->getKey()));
        $this->assertNotNull(User::query()->find($witnessOwner->getKey()));
        $witnessAccounts = TenantContext::run($witness, fn () => Account::query()->count());
        $this->assertSame(1, $witnessAccounts, 'The witness tenant must keep its own data untouched.');
        $this->assertSame(
            1,
            DB::table('personal_access_tokens')->where('abilities', 'like', '%tenant:'.$witness->getKey().'%')->count(),
            "The witness tenant's API token must survive.",
        );
    }

    public function test_a_tenant_canceled_less_than_the_threshold_is_left_intact(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'owner@example.test', Permissions::OWNER);
        $tenantId = $tenant->getKey();

        $this->insertCashierSubscription($tenantId, 'canceled', now()->subDays(29));
        $tenant->forceFill(['subscription_canceled_at' => now()->subDays(29)])->save();
        $this->clearDatabaseTenantContext();

        (new PurgeCanceledTenantsJob)->handle();

        $this->assertNotNull(Tenant::query()->find($tenantId), 'A cancellation inside the 30-day window must not be purged yet.');
        $this->assertNotNull(User::query()->find($owner->getKey()));
        $membership = TenantContext::run($tenant, fn () => Membership::query()->where('user_id', $owner->getKey())->first());
        $this->assertNotNull($membership);
    }

    /**
     * Simulează EXACT bug-ul de reactivare corectat separat în `ProcessStripeWebhookJob`
     * (ADR-012, „Implementare"): `subscription_canceled_at` a rămas — dintr-un motiv
     * oarecare — vechi de peste 30 de zile, dar abonamentul Cashier e din nou `active`.
     * Garda jobului trebuie să se bazeze pe STAREA reală, nu doar pe coloană.
     */
    public function test_a_tenant_canceled_past_the_threshold_but_with_a_currently_valid_subscription_is_left_intact(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'owner@example.test', Permissions::OWNER);
        $tenantId = $tenant->getKey();

        $this->insertCashierSubscription($tenantId, 'active', null);
        $tenant->forceFill(['subscription_canceled_at' => now()->subDays(31)])->save();
        $this->clearDatabaseTenantContext();

        (new PurgeCanceledTenantsJob)->handle();

        $this->assertNotNull(Tenant::query()->find($tenantId), 'A tenant with a currently valid Cashier subscription must never be purged.');
        $this->assertNotNull(User::query()->find($owner->getKey()));
    }

    public function test_users_with_a_membership_elsewhere_active_or_deactivated_are_kept(): void
    {
        $toPurge = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $witness = $this->makeTenant('cascade', 'Cascade Metal Works');

        $activeElsewhere = $this->makeMember($toPurge, 'active-elsewhere@example.test', Permissions::AGENT);
        $this->makeMember($witness, 'active-elsewhere@example.test', Permissions::VIEWER, $activeElsewhere);

        $deactivatedElsewhere = $this->makeMember($toPurge, 'deactivated-elsewhere@example.test', Permissions::AGENT);
        $this->makeMember($witness, 'deactivated-elsewhere@example.test', Permissions::VIEWER, $deactivatedElsewhere);
        TenantContext::run($witness, function () use ($deactivatedElsewhere): void {
            Membership::query()->where('user_id', $deactivatedElsewhere->getKey())->update([
                'status' => Membership::STATUS_DEACTIVATED,
                'deactivated_at' => now(),
            ]);
        });

        $toPurge->forceFill(['subscription_canceled_at' => now()->subDays(31)])->save();
        $this->clearDatabaseTenantContext();

        (new PurgeCanceledTenantsJob)->handle();

        $this->assertNull(Tenant::query()->find($toPurge->getKey()));
        $this->assertNotNull(User::query()->find($activeElsewhere->getKey()), 'An ACTIVE membership elsewhere must keep the user.');
        $this->assertNotNull(User::query()->find($deactivatedElsewhere->getKey()), 'ADR-011 never hard-deletes a membership — a DEACTIVATED one elsewhere must keep the user too.');

        $activeMembership = TenantContext::run($witness, fn () => Membership::query()->where('user_id', $activeElsewhere->getKey())->first());
        $this->assertSame(Membership::STATUS_ACTIVE, $activeMembership->status);

        $deactivatedMembership = TenantContext::run($witness, fn () => Membership::query()->where('user_id', $deactivatedElsewhere->getKey())->first());
        $this->assertSame(Membership::STATUS_DEACTIVATED, $deactivatedMembership->status, 'The untouched membership in the witness tenant must survive exactly as it was.');
    }

    /**
     * Referință reziduală SINTETICĂ, într-un tenant care NU e purjat — proba mecanismului
     * comun (`App\Support\Members\OrphanUserCleanup`), deja verificat identic în
     * `PruneExpiredInvitationsJobTest::test_a_referenced_user_is_kept_and_the_next_orphan_is_still_deleted`.
     * Un al doilea candidat orfan, FĂRĂ nicio referință, trebuie totuși șters în ACEEAȘI
     * rulare — dovada că prinderea `23503` nu lasă nicio tranzacție otrăvită (`25P02`).
     */
    public function test_an_orphan_user_with_a_residual_reference_is_kept_and_the_rest_of_the_purge_continues(): void
    {
        $toPurge = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $witness = $this->makeTenant('cascade', 'Cascade Metal Works');
        $witnessOwner = $this->makeMember($witness, 'witness-owner@example.test', Permissions::OWNER);

        $referenced = $this->makeMember($toPurge, 'referenced@example.test', Permissions::AGENT);
        $orphan = $this->makeMember($toPurge, 'orphan@example.test', Permissions::AGENT);

        TenantContext::run($witness, function () use ($referenced): void {
            $account = new Account(['name' => 'Residual Reference LLC']);
            $account->created_by = $referenced->getKey();
            $account->save();
        });

        $toPurge->forceFill(['subscription_canceled_at' => now()->subDays(31)])->save();
        $this->clearDatabaseTenantContext();

        (new PurgeCanceledTenantsJob)->handle();

        $this->assertNull(Tenant::query()->find($toPurge->getKey()));
        $this->assertNotNull(User::query()->find($witnessOwner->getKey()));
        $this->assertNotNull(User::query()->find($referenced->getKey()), 'Still referenced by a row in a surviving tenant — kept, with a warning logged.');
        $this->assertNull(User::query()->find($orphan->getKey()), 'The caught FK violation for the previous candidate must not abort the transaction for this one.');
    }

    public function test_it_is_idempotent_on_a_second_run(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'owner@example.test', Permissions::OWNER);
        $tenantId = $tenant->getKey();

        $this->insertCashierSubscription($tenantId, 'canceled', now()->subDays(31));
        $tenant->forceFill(['subscription_canceled_at' => now()->subDays(31)])->save();
        $this->clearDatabaseTenantContext();

        (new PurgeCanceledTenantsJob)->handle();
        $this->assertNull(Tenant::query()->find($tenantId));
        $this->assertNull(User::query()->find($owner->getKey()));

        // Une deuxième exécution ne doit rien trouver à purger — aucune exception, aucun effet.
        (new PurgeCanceledTenantsJob)->handle();
        $this->assertNull(Tenant::query()->find($tenantId));
    }

    public function test_the_purge_is_scheduled_daily(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => $event->description === PurgeCanceledTenantsJob::class);

        $this->assertNotNull($event, 'Intrarea de scheduler pentru purjarea tenanților anulați lipsește din routes/console.php.');
        $this->assertSame('0 0 * * *', $event->expression, 'daily() — pragul e în ZILE (30), o cadență lunară i-ar dubla practic fereastra.');
    }

    /**
     * Verificarea EMPIRICĂ, exhaustivă (`ModelTenantScopeCoverageTest`): sursa de adevăr
     * pentru „ce tabelă are `tenant_id`" e schema REALĂ, nu o listă din memorie — include
     * DELIBERAT `roles`/`model_has_roles`/`model_has_permissions` (Spatie), pe care cele
     * două teste arhitecturale existente le exclud explicit fiindcă nu sunt modele Eloquent
     * — dar tot au `tenant_id`, tot trebuie golite la purjare, și NU au nicio cascadă spre
     * `tenants` (vezi docblock-ul jobului).
     */
    private function assertNoTenantScopedRowsRemain(string $tenantId): void
    {
        $tables = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_name', 'tenant_id')
            ->pluck('table_name');

        $this->assertGreaterThan(25, $tables->count(), 'Schema pare incompletă — verifică migrațiile.');

        $offenders = [];
        foreach ($tables as $table) {
            $count = DB::table($table)->where('tenant_id', $tenantId)->count();

            if ($count > 0) {
                $offenders[] = "{$table} ({$count})";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Rânduri rămase după purjare, în tabele cu `tenant_id`:\n".implode("\n", $offenders),
        );
    }

    /**
     * Atinge un eșantion larg din cele 32 de tabele cascadate + cele două excepții
     * (Spatie roluri/permisiuni per tenant) — suficient ca o regresie de cascadă să nu
     * treacă neobservată, fără să reproducă fiecare tabelă din schemă.
     */
    private function seedFullDataset(Tenant $tenant, User $owner): void
    {
        TenantContext::run($tenant, function () use ($tenant, $owner): void {
            $account = new Account([
                'name' => 'Marlin Metals LLC',
                'status' => Account::STATUS_ACTIVE,
                'owner_user_id' => $owner->getKey(),
            ]);
            $account->created_by = $owner->getKey();
            $account->save();

            $pipeline = $this->makeDefaultPipeline($tenant);
            $variant = $this->makeVariant();
            $location = $this->makeDefaultLocation();
            $this->setInventory($variant, $location, 100);

            $order = $this->confirmedOrder($account, $owner, 500.0);
            $invoice = $this->sentInvoice($order, 500.0, 200.0);

            $now = now();

            // Trei tabele fără model Eloquent dedicat în felia asta — inserate direct, ca
            // să dovedească faptul că nu doar entitățile „mari" (Account/Order/Invoice) sunt
            // curățate de cascada de pe `tenants`.
            DB::table('bulk_operations')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenant->getKey(),
                'user_id' => $owner->getKey(),
                'resource_type' => 'accounts',
                'action' => 'export',
                'filter_snapshot' => json_encode([]),
                'total_rows' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('saved_views')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenant->getKey(),
                'user_id' => $owner->getKey(),
                'resource_type' => 'accounts',
                'name' => 'My accounts',
                'filters' => json_encode([]),
                'columns' => json_encode(['name']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Emis pe calea REALĂ: `ApiToken::issue()` scrie și rândul Sanctum
            // (`personal_access_tokens`), fără `tenant_id` — audit 2026-09-23, P1.
            ApiToken::issue($owner, 'Integration token', ['*']);

            DB::table('idempotency_keys')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenant->getKey(),
                'key' => Str::random(20),
                'request_hash' => hash('sha256', 'x'),
                'expires_at' => $now->copy()->addDay(),
            ]);
        });

        $this->clearDatabaseTenantContext();
    }

    private function insertCashierSubscription(string $tenantId, string $status, mixed $endsAt): string
    {
        $now = now();

        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'user_id' => $tenantId,
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(14),
            'stripe_status' => $status,
            'ends_at' => $endsAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('subscription_items')->insert([
            'subscription_id' => $subscriptionId,
            'stripe_id' => 'si_'.Str::random(14),
            'stripe_product' => 'prod_test',
            'stripe_price' => 'price_test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (string) $subscriptionId;
    }

    /** Scrie pe discul REAL `local` (vezi docblock-ul clasei pentru de ce nu `Storage::fake()`). */
    private function writeTenantFiles(string $tenantId): void
    {
        $this->tenantIdsWithFiles[] = $tenantId;
        $disk = Storage::disk('local');

        foreach (['exports', 'gdpr-exports', 'imports', 'invoices', 'reports'] as $root) {
            $disk->put("{$root}/{$tenantId}/probe.txt", 'gdpr-purge-test');
        }
    }
}
