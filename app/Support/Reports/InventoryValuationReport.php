<?php

namespace App\Support\Reports;

use App\Models\InventoryLevel;
use App\Models\ReportDefinition;

/**
 * „Inventory Valuation" (specs.md §16.3): valoare totală de stoc (`on_hand × variants.cost`)
 * per locație și per categorie, din `inventory_levels` + `variants.cost` (§10.2).
 *
 * DECIZIE (semnalată explicit în raportul lotului K, rafinată la review) — `variants.cost`
 * e ascuns pentru Agent/Viewer în UI (`Permissions::canViewCost()`, §7.4). Rândul se trage
 * diferit pe cele DOUĂ căi prin care datele astea pot ajunge la un Agent:
 *  1. **Fișierul** (descărcat sau trimis pe email, §16.2 pct. 4) — NU filtrat/redactat.
 *     `report_runs.file_path` e UN SINGUR fișier per rulare, trimis identic la TOATE
 *     adresele din `recipients` (jsonb, simple string-uri de email — nu neapărat conturi
 *     ale tenantului); n-are cum să existe un fișier diferit per destinatar fără o
 *     schimbare arhitecturală. Autorizarea de a CREA/EDITA un `report_definitions` (deci
 *     de a alege `recipients`) cere `reports.manage` — doar Owner/Manager, aceiași care văd
 *     oricum `variants.cost` în restul aplicației; alegerea destinatarilor rămâne
 *     responsabilitatea lor. Limitare ASUMATĂ, nu rezolvată — semnalată separat.
 *  2. **Previzualizarea sincronă ÎN APLICAȚIE** (`Reports/Show.tsx`, US-REP-02) — ASCUNSĂ
 *     pentru cine nu poate vedea costul (`exposesCost()` de mai jos, citit de
 *     `ReportController::builtInPreview()`). Aici nu e vorba de „cine a ales destinatarii",
 *     ci de ecranul aplicației în sine: §7.4 dă Agentului „R" pe rapoartele unde e
 *     destinatar, dar NICIODATĂ acces la `variants.cost` — un tabel randat direct în
 *     `Reports/Show` ar fi exact acel acces, necondiționat de nimic.
 * Interzis explicit („nu inventa o permisiune nouă"): îngustarea de mai jos se face cu
 * `Permissions::canViewCost()`, deja existent, nu cu o permisiune nouă `reports.view_cost`.
 * Nota UI (`ReportForm.tsx`) atrage atenția Owner/Manager la alegerea unui destinatar
 * Agent pe acest raport (privind fișierul), fără să blocheze nimic — un nudge, nu o poartă.
 */
final class InventoryValuationReport implements BuiltInReport
{
    public function reportType(): string
    {
        return ReportDefinition::TYPE_INVENTORY_VALUATION;
    }

    public function title(): string
    {
        return 'Inventory Valuation';
    }

    public function exposesCost(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return ['Location', 'Category', 'On hand (units)', 'Total value'];
    }

    public function rows(): array
    {
        // ~1.500 rânduri semănate în TOT tenantul-vitrină (măsurat) — mărginit de
        // (variante × locații), nu de un ledger de mișcări; `->get()` simplu e sigur,
        // fără nevoie de `ExportQueryChunker`. `with()` explicit (nu `cursor()`, care l-ar
        // ignora tăcut — capcana N+1 măsurată în Faza 3) evită interogări per rând.
        $levels = InventoryLevel::query()
            ->with(['variant:id,product_id,cost', 'variant.product:id,category', 'location:id,name'])
            ->get();

        /** @var array<string, array{location: string, category: string, onHand: int, value: float}> $grouped */
        $grouped = [];

        foreach ($levels as $level) {
            $locationName = $level->location?->name ?? 'Unknown location';
            $category = $level->variant?->product?->category ?? 'Uncategorized';
            $key = $locationName."\0".$category;

            $grouped[$key] ??= ['location' => $locationName, 'category' => $category, 'onHand' => 0, 'value' => 0.0];
            $grouped[$key]['onHand'] += $level->on_hand;
            $grouped[$key]['value'] += $level->on_hand * (float) ($level->variant?->cost ?? 0);
        }

        $rows = array_values($grouped);

        usort($rows, fn (array $a, array $b): int => [$a['location'], $a['category']] <=> [$b['location'], $b['category']]);

        return array_map(
            fn (array $row): array => [$row['location'], $row['category'], $row['onHand'], round($row['value'], 2)],
            $rows,
        );
    }
}
