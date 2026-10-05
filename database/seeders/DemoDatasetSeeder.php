<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Observers\ActivityLogObserver;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\Demo\AccountsAndContactsSeeder;
use Database\Seeders\Demo\ActivityVarietySeeder;
use Database\Seeders\Demo\BillingSeeder;
use Database\Seeders\Demo\CarrierSettingsSeeder;
use Database\Seeders\Demo\CatalogSeeder;
use Database\Seeders\Demo\DealsSeeder;
use Database\Seeders\Demo\ImportFixtureSeeder;
use Database\Seeders\Demo\ReportsSeeder;
use Database\Seeders\Demo\StockAndOrdersSeeder;
use Database\Seeders\Demo\TenantsSeeder;
use Database\Seeders\Demo\UsersAndMembershipsSeeder;
use Database\Seeders\Support\ActivityLogRecorder;
use Database\Seeders\Support\ChunkedWriter;
use Illuminate\Database\Seeder;

/**
 * Orchestratorul de seed la volum (plan §7.8, specs.md §21). E un "job de sistem"
 * (ADR-014, pct. 4): NU rulează pe conexiunea cu BYPASSRLS — iterează tenanții explicit,
 * cu o tranzacție și un context per tenant (`TenantContext::run`), pentru că politica RLS
 * are doar `USING`, iar PostgreSQL o aplică ȘI ca `WITH CHECK` — un INSERT fără context
 * eșuează cu "new row violates row-level security policy" (verificat, ADR-003/ADR-014).
 *
 * Ordinea contează:
 *  1. tenanții (fără RLS) și fixture-ul static de import (fără DB) — în afara oricărui context;
 *  2. cei 4 utilizatori demo + colegi + membership-uri (RLS proprie pe `memberships`);
 *  3. `RoleAndPermissionSeeder` — permisiunile global + cele 4 roluri per tenant (deja scris,
 *     doar apelat aici, per task brief);
 *  4. atribuirea rolurilor (`setPermissionsTeamId` + `assignRole`), abia după ce rolurile există;
 *  5. per tenant, ÎN interiorul TenantContext::run: catalog → conturi/contacte → deals →
 *     stoc/comenzi/facturare, cu un singur `ActivityLogRecorder` comun.
 */
class DemoDatasetSeeder extends Seeder
{
    /** @var array<string, array<string, mixed>> */
    private const TENANTS = [
        'marlin' => [
            'name' => 'Marlin Fasteners & Supply Co.',
            'industry' => 'Industrial Fasteners Distributor',
            'vertical' => 'fasteners',
            'code' => 'MRL',
            'carrier' => 'demo',
            'accounts' => 4000,
            'orders' => 30000,
            'products' => 180,
        ],
        'cascade' => [
            'name' => 'Cascade Hydraulic Components',
            'industry' => 'Hydraulic Components Distributor',
            'vertical' => 'hydraulics',
            'code' => 'CAS',
            'carrier' => 'shippo',
            'accounts' => 2500,
            'orders' => 15000,
            'products' => 120,
        ],
        'northgate' => [
            'name' => 'Northgate Restaurant Supply',
            'industry' => 'Foodservice Equipment & Supplies',
            'vertical' => 'foodservice',
            'code' => 'NGT',
            'carrier' => 'shippo',   // era `easypost`, scos la 2026-09-12 — vezi CarrierSettingsSeeder
            'accounts' => 1500,
            'orders' => 5000,
            'products' => 90,
        ],
    ];

    private float $scale = 1.0;

    /**
     * Fracțiunea din volumul §21.1 de generat — 1 pentru demo, sub 1 pentru suita E2E.
     */
    public function scaledTo(float $scale): static
    {
        $this->scale = $scale;

        return $this;
    }

    /**
     * Volumele §21.1, la scara cerută. Sub scara completă, fiecare volum are un minim care ține
     * fluxurile E2E posibile (conturi pentru fiecare responsabil, deals pe mai multe etape,
     * comenzi pe toate stările), nu doar o fracțiune aritmetică: 0,1% din cele 1.500 de conturi
     * Northgate ar fi un singur cont.
     *
     * @return array<string, array<string, mixed>>
     */
    private function tenantConfigs(): array
    {
        if ($this->scale >= 1.0) {
            return self::TENANTS;
        }

        return array_map(fn (array $config): array => [
            ...$config,
            'accounts' => max(40, (int) round($config['accounts'] * $this->scale)),
            'orders' => max(60, (int) round($config['orders'] * $this->scale)),
            'products' => max(12, (int) round($config['products'] * $this->scale)),
        ], self::TENANTS);
    }

    public function run(): void
    {
        $start = microtime(true);

        $configs = $this->tenantConfigs();

        (new ImportFixtureSeeder)->run($this->command);

        $tenants = (new TenantsSeeder)->run($configs);

        $this->command?->info('Users & memberships');
        $usersByTenant = (new UsersAndMembershipsSeeder)->run($tenants, $configs);

        $this->call(RoleAndPermissionSeeder::class);

        (new UsersAndMembershipsSeeder)->assignRoles($tenants, $usersByTenant);

        foreach ($configs as $slug => $config) {
            $tenant = $tenants[$slug];
            $staff = $usersByTenant[$slug];

            // Instrumentarea LIVE oprită pe tot blocul: seed-ul își scrie singur jurnalul,
            // cu date istorice (`ActivityLogRecorder`). `CatalogSeeder` creează produsele și
            // variantele prin Eloquent, deci observerul adăuga ~80 de rânduri `created` per
            // tenant, toate la minutul rulării — cele mai recente din tabelă, deci exact cele
            // care umpleau feed-ul „Recent activity" al dashboard-ului.
            ActivityLogObserver::withoutRecording(fn () => TenantContext::run($tenant, function () use ($tenant, $config, $staff): void {
                $this->command?->info("Tenant: {$config['name']} ({$config['accounts']} accounts / {$config['orders']} orders)");

                $activityLog = new ActivityLogRecorder(
                    new ChunkedWriter(ActivityLog::class, 1000, $this->command, 'Activity log', $config['orders'] * 2)
                );

                (new CarrierSettingsSeeder)->run($tenant, $config);

                $catalog = (new CatalogSeeder)->run($tenant, $config);

                $accountsResult = (new AccountsAndContactsSeeder)->run($tenant, $config, $staff, $this->command, $activityLog);

                $dealsResult = (new DealsSeeder)->run($tenant, $config, $catalog['pipeline_id'], $catalog['stages'], $catalog['categories'], $accountsResult, $staff, $this->command, $activityLog);

                $orderSummaries = (new StockAndOrdersSeeder)->run($tenant, $config, $catalog, $accountsResult, $dealsResult, $staff, $this->command, $activityLog);

                (new BillingSeeder)->run($tenant, $config, $orderSummaries, $accountsResult, $this->command, $activityLog);

                // Rapoartele își citesc numărul de rânduri din rapoartele REALE, deci au
                // nevoie de catalog, stoc și afaceri deja scrise.
                (new ReportsSeeder)->run($tenant, $staff, $this->command);

                // ULTIMUL: citește id-uri din tabelele deja scrise, deci are nevoie de toate
                // seederele de entități înaintea lui.
                (new ActivityVarietySeeder)->run($tenant, $staff, $this->command, $activityLog);

                $activityLog->flush();
            }));
        }

        $elapsed = round(microtime(true) - $start, 1);
        $this->command?->info("Demo dataset seeded in {$elapsed}s.");
    }
}
