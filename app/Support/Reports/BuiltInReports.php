<?php

namespace App\Support\Reports;

use InvalidArgumentException;

/**
 * Registrul `report_type` → clasa `BuiltInReport` care știe să-l genereze — mirror-ul
 * `App\Support\Exports\ExportableResources` pentru rapoarte. Sursă unică pentru
 * `GenerateReportJob` (fișierul din coadă) și `ReportController::runNow()` (randarea
 * sincronă, US-REP-02): amândoi rezolvă raportul prin același nume, deci nu pot ajunge să
 * calculeze lucruri diferite pentru același `report_type`.
 */
final class BuiltInReports
{
    /** @return array<string, class-string<BuiltInReport>> */
    public static function map(): array
    {
        return [
            'deal_velocity' => DealVelocityReport::class,
            'inventory_valuation' => InventoryValuationReport::class,
        ];
    }

    public static function isBuiltIn(string $reportType): bool
    {
        return array_key_exists($reportType, self::map());
    }

    public static function resolve(string $reportType): BuiltInReport
    {
        $class = self::map()[$reportType] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("\"{$reportType}\" nu e un raport built-in înregistrat.");
        }

        return app($class);
    }
}
