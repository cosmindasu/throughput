<?php

namespace App\Console\Commands;

use App\Actions\Stock\ReconcileStockAction;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * FR-STOCK-01, §10.4 — comandă de sistem (`.ai/rules/tenancy.md`, ADR-014 pct. 4): fără
 * tenant propriu, iterează tenanții explicit, cu o tranzacție și un context PER TENANT
 * (`TenantContext::run()`), pe conexiunea aplicației — niciodată pe cea cu BYPASSRLS.
 *
 * Candidat explicit de demo (specs.md §10.4): „verifică integritatea stocului, 0
 * discrepanțe pe date semănate". Programată săptămânal din `routes/console.php`;
 * rulabilă manual oricând, inclusiv pe un singur tenant (`--tenant=`).
 *
 * Cod de ieșire `FAILURE` dacă există cel puțin o divergență — util pentru monitorizare
 * (§25.2), fără să încalce „nu corectează automat": comanda doar raportează, corecția
 * rămâne o mișcare de tip `adjustment`, scrisă de un om.
 */
class StockReconcile extends Command
{
    protected $signature = 'stock:reconcile {--tenant= : Slug-ul unui singur tenant de verificat (implicit: toți)}';

    protected $description = 'Recalculate inventory_levels.on_hand from stock_movements and report divergences (FR-STOCK-01) — never corrects';

    public function handle(ReconcileStockAction $action): int
    {
        $tenants = $this->option('tenant') !== null
            ? Tenant::query()->where('slug', $this->option('tenant'))->get()
            : Tenant::query()->get();

        if ($tenants->isEmpty()) {
            $this->components->error('Niciun tenant găsit.');

            return self::FAILURE;
        }

        $totalChecked = 0;
        $totalDivergences = 0;

        foreach ($tenants as $tenant) {
            $report = TenantContext::run($tenant, fn () => $action->execute());

            $totalChecked += $report['checked'];

            if ($report['divergences'] === []) {
                $this->components->twoColumnDetail(
                    "<fg=green>✓</> {$tenant->slug}",
                    "{$report['checked']} perechi variantă/locație, 0 divergențe"
                );

                continue;
            }

            $totalDivergences += count($report['divergences']);

            $this->components->twoColumnDetail(
                "<fg=red>✗</> {$tenant->slug}",
                count($report['divergences']).' divergențe din '.$report['checked'].' perechi verificate'
            );

            $this->table(
                ['Variant', 'Location', 'inventory_levels.on_hand', 'SUM(stock_movements.delta)'],
                array_map(
                    fn (array $row) => [$row['variant_id'], $row['location_id'], $row['projected_on_hand'], $row['calculated_on_hand']],
                    $report['divergences']
                )
            );
        }

        $this->newLine();

        if ($totalDivergences > 0) {
            $this->components->error(
                "{$totalDivergences} divergențe pe ".$tenants->count().' tenant(i). Nimic nu s-a corectat automat (FR-STOCK-01) — '.
                'corecția e o mișcare nouă de tip `adjustment`, scrisă de un om, niciodată un UPDATE pe proiecție.'
            );

            return self::FAILURE;
        }

        $this->components->info("stock:reconcile: {$totalChecked} perechi variantă/locație verificate pe ".$tenants->count().' tenant(i), 0 divergențe.');

        return self::SUCCESS;
    }
}
