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
     * abonamentului — se adaugă aici în Faza 5 (valul 2, lotul I), odată cu ecranele care
     * introduc acțiunile. Plafonul de rânduri nu e o rută: se verifică în operație
     * (`exceedsBulkRowCap()`), fiindcă numărul de rânduri se cunoaște abia după filtru.
     *
     * Ecranul de ștergere a workspace-ului nu există încă. Numele rutei e fixat de acum, ca
     * guardrail-ul să fie activ din clipa în care ruta apare, nu după primul vizitator care
     * o găsește — iar `DemoModeGuardrailsTest` pică dacă apare sub alt nume. Aceeași
     * disciplină pentru rândurile adăugate în Faza 5: numele sunt fixate ÎNAINTE ca ruta
     * să existe, iar testul semnalează orice rută care apare sub un nume neguardat.
     *
     * ADR-022, Lot I18N Val 2 — `message` ține o CHEIE de traducere
     * (`lang/{en,fr}/flash.php`, namespace `demo.*`), NU textul rezolvat: array-ul rămâne
     * o constantă de clasă, iar PHP nu acceptă apeluri de funcție (`__()`) în
     * inițializarea unui `const`. `refusal()` mai jos face `__($key)` la APELARE, nu la
     * definirea array-ului — motivul pentru care valorile de mai jos arată ca niște chei
     * de traducere, nu ca fraze în engleză.
     *
     * @var array<string, array{routes: list<string>, message: string}>
     */
    public const GUARDED_ACTIONS = [
        'workspace.delete' => [
            'routes' => ['workspace.destroy'],
            'message' => 'flash.demo.workspace_delete_disabled',
        ],

        // US-TEN-03, adăugat de pachetul „Membri și roluri" (nu era în tabelul §22.2
        // — semnalat în raportul pachetului, recomandat spre specs.md). Motivul e
        // specific acestei acțiuni, nu generic: conturile demo (§4.2) sunt LOGIN-URI
        // PARTAJATE — `demo.owner@throughput.dev` e folosit de fiecare vizitator care
        // apasă „Log in as Owner". Dezactivarea unui asemenea membru ar rupe
        // autentificarea tuturor vizitatorilor următori până la reset-ul de la 03:00
        // UTC, un risc distructiv de aceeași natură ca ștergerea unui workspace, deci
        // tratat la fel — activ de la publicare, nu amânat, spre deosebire de restul
        // rândului „Eliminarea ultimului Owner" din §22.2 (acela rămâne oricum blocat
        // necondiționat de BR-TEN-01, cu sau fără DEMO_MODE).
        'members.deactivate' => [
            'routes' => ['settings.members.deactivate'],
            'message' => 'flash.demo.members_deactivate_disabled',
        ],

        // §22.2, rândul „Eliminarea ultimului Owner" („Ar bloca accesul — deja prevenit de
        // BR-TEN-01, dublu strat"), cablat acum, cu ecranul de membri (Faza 5).
        //
        // Rândul are DOUĂ trape, nu una, fiindcă BR-TEN-04 spune explicit că „eliminarea"
        // unui membru NU e un DELETE fizic: acțiunea e dezactivarea (deja oprită mai sus) —
        // iar al doilea drum către „workspace fără Owner" e RETROGRADAREA ultimului Owner
        // (BR-TEN-01 le numește împreună: „ultima eliminare/retrogradare"). Deci schimbarea
        // de rol intră și ea aici.
        //
        // Garda de MEDIU e deliberat mai GROSIERĂ decât regula de business: oprește ORICE
        // schimbare de rol / eliminare în demo, nu doar pe ultima. Motivul nu e comoditatea,
        // e același cu al rândului `members.deactivate` de mai sus: conturile demo sunt
        // LOGIN-URI PARTAJATE (§4.2), deci a-l retrograda pe `demo.manager@throughput.dev`
        // la Viewer strică experiența fiecărui vizitator care apasă „Log in as Manager"
        // până la reset-ul de la 03:00 UTC. Stratul FIN (doar ultimul Owner, activ și în
        // afara demo-ului) rămâne al lui BR-TEN-01, server-side, în fluxul de membri —
        // „dublu strat" exact cum îl descrie §22.2.
        'members.change-role' => [
            // `settings.members.role.update` e numele REAL, ales de fluxul de membri
            // construit în paralel în acest val (`routes/web/settings.php`, PATCH
            // `/settings/members/{membership}/role`). Celelalte două rămân în listă ca
            // variante plauzibile dacă ruta e vreodată redenumită; `DemoModeGuardrailsPhase5Test`
            // semnalează orice al patrulea nume care ar apărea neguardat.
            'routes' => ['settings.members.role.update', 'settings.members.role', 'settings.members.update-role'],
            'message' => 'flash.demo.members_change_role_disabled',
        ],

        // NU include `settings.members.invitations.destroy` (revocarea unei invitații încă
        // NEACCEPTATE, adăugată de fluxul de membri în acest val): aceea nu elimină un
        // membru — nu există încă un membru — și e complet reversibilă printr-o invitație
        // nouă. §22.2 vorbește despre eliminarea unui OWNER existent.
        'members.remove' => [
            'routes' => ['settings.members.destroy'],
            'message' => 'flash.demo.members_remove_disabled',
        ],

        // §22.2, rândul „Revocarea în masă a tuturor jetoanelor API" („Amploare
        // disproporționată pentru un demo"), cablat acum, cu jetoanele API (Faza 5).
        //
        // ATENȚIE la ce NU e aici: `settings.api-tokens.destroy` — revocarea UNUI jeton —
        // rămâne permisă în demo. §22.2 numește doar acțiunea în MASĂ, iar un vizitator care
        // își revocă propriul jeton de probă demonstrează fluxul fără să strice nimic
        // altcuiva. Dacă proprietarul vrea varianta grosieră, e o singură valoare în lista
        // de mai jos — vezi raportul lotului.
        'api-tokens.revoke-all' => [
            'routes' => ['settings.api-tokens.destroy-all', 'settings.api-tokens.revoke-all'],
            'message' => 'flash.demo.api_tokens_revoke_all_disabled',
        ],

        // §22.2, rândul „Anularea reală a abonamentului Stripe" („Rulează oricum în test
        // mode (§22.4), dar acțiunea e ascunsă pentru claritate"), cablat acum, cu
        // billing-ul (Faza 5). Ruta nu există: pagina de billing trimite azi la Stripe
        // Customer Portal. Numele e fixat de acum, ca la `workspace.destroy`.
        'subscription.cancel' => [
            'routes' => ['settings.billing.cancel', 'settings.subscription.cancel'],
            'message' => 'flash.demo.subscription_cancel_disabled',
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
        // `:owner` e folosit doar de două dintre mesaje (schimbare de rol / eliminare de
        // membru); celelalte îl ignoră. Numele rolului vine din `lang/{locale}/roles.php`,
        // sursa unică adăugată la Valul 3 — nu scris literal în catalogul de flash, ca să nu
        // existe încă un loc de actualizat la revizia traducerii franceze.
        return __(self::GUARDED_ACTIONS[$action]['message'], ['owner' => __('roles.owner')]);
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

    /**
     * ADR-022, Lot I18N Val 2 — singurul apelant e `ValidationException::withMessages()`
     * din `App\Actions\Bulk\DispatchBulkOperationAction` (verificat: nicio altă utilizare
     * în cod), deci mesajul trece prin `rules.bulk.demo_row_cap`, nu prin `sprintf()` brut.
     * `GUARDED_ACTIONS[...]['message']`/`refusal()` de mai sus au fost traduse separat,
     * pe `lang/{en,fr}/flash.php` (namespace `demo.*`): sunt mesaje FLASH
     * (`back()->with('error', ...)`/`abort(403, ...)`), un alt domeniu al lotului I18N
     * (nu regulă de business prin `ValidationException`) — de-asta au un catalog propriu.
     */
    public static function bulkRowCapRefusal(int $rows): string
    {
        // `count` suprascris explicit (formatat cu separator de mii) — `trans_choice()`
        // folosește `$rows` BRUT (parametrul de mai jos) doar ca să aleagă forma
        // singular/plural, apoi înlocuiește `:count` cu ce găsește în `$replace['count']`,
        // dacă e prezent (`Translator::choice()`). Lot I18N, Val 5: `number_format()` e fixat pe
        // convenția engleză, deci un francofon citea „1,234" ca unu-virgulă-doi-trei-patru.
        return trans_choice('rules.bulk.demo_row_cap', $rows, [
            'count' => LocaleFormat::count($rows),
            'cap' => LocaleFormat::count((int) self::bulkRowCap()),
        ]);
    }
}
