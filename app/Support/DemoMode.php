<?php

namespace App\Support;

/**
 * `DEMO_MODE` și guardrail-urile lui (specs.md §22.2), citite dintr-un singur loc.
 *
 * Producția ESTE demo-ul public, cu scriere reală. Codul care decide dacă o acțiune e
 * permisă întreabă aici, nu citește config-ul pe cont propriu — altfel butonul ascuns din
 * props și refuzul de pe server ajung să folosească reguli diferite.
 */
final class DemoMode
{
    /**
     * Acțiunile oprite în DEMO_MODE indiferent de rol, inclusiv pentru Owner (BR-DEMO-01),
     * cu rutele care le execută.
     *
     * Faza 2 cablează doar subsetul distructiv obligatoriu la publicare (§22.2, primele două
     * rânduri). Restul tabelului — ultimul Owner, revocarea în masă a jetoanelor, anularea
     * abonamentului — se adaugă aici în Faza 5, odată cu ecranele care introduc acțiunile.
     * Plafonul de rânduri nu e o rută: se verifică în operație (`exceedsBulkRowCap()`),
     * fiindcă numărul de rânduri se cunoaște abia după filtru.
     *
     * Ecranul de ștergere a workspace-ului nu există încă. Numele rutei e fixat de acum, ca
     * guardrail-ul să fie activ din clipa în care ruta apare, nu după primul vizitator care
     * o găsește — iar `DemoModeGuardrailsTest` pică dacă apare sub alt nume.
     *
     * @var array<string, array{routes: list<string>, message: string}>
     */
    public const GUARDED_ACTIONS = [
        'workspace.delete' => [
            'routes' => ['workspace.destroy'],
            'message' => 'Deleting a workspace is disabled in the public demo. The demo data resets every night at 03:00 UTC.',
        ],
    ];

    public static function enabled(): bool
    {
        return (bool) config('throughput.demo.mode');
    }

    /**
     * Sursa lui `can` din props pentru acțiunile de mai sus: butonul lipsește din interfață,
     * iar serverul refuză oricum (EnsureDemoModeGuardrails) — două straturi, aceeași regulă.
     */
    public static function allows(string $action): bool
    {
        return ! (self::enabled() && array_key_exists($action, self::GUARDED_ACTIONS));
    }

    public static function guardedActionForRoute(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        foreach (self::GUARDED_ACTIONS as $action => $guard) {
            if (in_array($routeName, $guard['routes'], true)) {
                return $action;
            }
        }

        return null;
    }

    public static function refusal(string $action): string
    {
        return self::GUARDED_ACTIONS[$action]['message'];
    }

    /**
     * Plafonul absolut de rânduri al unei operații în masă (§22.2, rândul doi): 60.000
     * implicit, deliberat de 3× peste ținta KPI. `null` în afara `DEMO_MODE`.
     */
    public static function bulkRowCap(): ?int
    {
        return self::enabled() ? (int) config('throughput.limits.bulk_max_rows') : null;
    }

    public static function exceedsBulkRowCap(int $rows): bool
    {
        $cap = self::bulkRowCap();

        return $cap !== null && $rows > $cap;
    }

    public static function bulkRowCapRefusal(int $rows): string
    {
        return sprintf(
            'This operation would touch %s rows. The public demo caps bulk operations at %s rows.',
            number_format($rows),
            number_format((int) self::bulkRowCap()),
        );
    }
}
