<?php

namespace App\Support\Reports;

/**
 * Contract comun rapoartelor built-in (specs.md §16.3): titlu, coloane, generatorul de
 * rânduri. `App\Jobs\Reports\GenerateReportJob` și `ReportController::runNow()` (rezultatul
 * sincron, US-REP-02) lucrează DOAR prin acest contract — a treia (FR-REP-02, Faza 2
 * viitoare: leaderboard agenți, cohort analysis) se adaugă cu o singură clasă nouă + o
 * linie în `BuiltInReports::map()`, fără să atingă jobul sau controller-ul.
 *
 * Rândurile sunt întotdeauna agregate (grupate pe etapă/pipeline sau locație/categorie),
 * deci mărimea lor e mărginită de cardinalitatea configurării tenantului (etape × pipeline-uri,
 * sau locații × categorii) — niciodată proporțională cu numărul de tranzacții/mișcări brute.
 * Asta le face sigure de rulat SINCRON în cererea HTTP pentru „Run now" (ADR-013 nu se
 * aplică: nicio interogare de-aici nu se apropie de costul unui apel extern sau al unui
 * randare DomPDF) — verificat cu EXPLAIN ANALYZE, vezi raportul lotului K, Faza 4.
 */
interface BuiltInReport
{
    /** Valoarea `report_definitions.report_type` care selectează acest raport. */
    public function reportType(): string;

    public function title(): string;

    /** @return list<string> */
    public function columns(): array;

    /**
     * Rândurile curente pentru tenantul din contextul activ — apelantul (job sau
     * controller) trebuie să fi restaurat deja `TenantContext` înainte de a chema asta.
     *
     * @return list<list<string|int|float|null>>
     */
    public function rows(): array;

    /**
     * Fix P1 (review) — raportul expune valori derivate din `variants.cost` (marjă),
     * ascunsă pentru Agent/Viewer în restul aplicației (`Permissions::canViewCost()`,
     * §7.4)? `ReportController::builtInPreview()` ascunde previzualizarea SINCRONĂ din
     * `Reports/Show.tsx` pentru cine nu poate vedea costul — NU și fișierul descărcabil/
     * trimis pe email (decizie separată, documentată în `InventoryValuationReport`).
     */
    public function exposesCost(): bool;
}
