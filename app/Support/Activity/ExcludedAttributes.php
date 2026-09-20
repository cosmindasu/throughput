<?php

namespace App\Support\Activity;

/**
 * BR-AUD-01, specs.md §17.1 — „nu se loghează niciodată parole, token-uri sau secrete în
 * `old_values`/`new_values` — o listă explicită de câmpuri excluse ... aplicată la nivelul
 * listener-ului, nu lăsată la latitudinea fiecărui apel."
 *
 * Sursă UNICĂ, aplicată de `App\Observers\ActivityLogObserver` (scrierea per-rând) ȘI de
 * `App\Support\Activity\BulkChunkActivityRecorder` (scrierea în masă) — un singur loc de
 * întreținut înseamnă că un al treilea consumator viitor al jurnalului nu poate uita
 * redactarea reinventând-o.
 *
 * `credentials` (App\Models\TenantCarrierSetting, cast `encrypted:array`, construit ÎN
 * PARALEL de alt lot, Faza 5) e pe listă DEFENSIV, deși acest lot nu observă acel model
 * (`ActivityLogServiceProvider` nu-l înregistrează) — dacă o versiune viitoare a
 * observării extinde lista de modele fără să recitească acest fișier, cheia tot nu ajunge
 * în jurnal. Apărare pe două straturi, ca izolarea de tenant (ADR-003).
 */
final class ExcludedAttributes
{
    /** Niciodată în jurnal — cheia dispare complet din `old_values`/`new_values`. */
    private const NEVER_LOGGED = [
        'password',
        'remember_token',
        'credentials',
    ];

    /** Cheia rămâne (arată CĂ s-a schimbat), valoarea se mascheajă parțial. */
    private const MASKED = [
        'stripe_customer_id',
    ];

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null `null` dacă intrarea era `null` SAU dacă, după
     *                                   redactare, n-a mai rămas niciun câmp de logat.
     */
    public static function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach (self::NEVER_LOGGED as $key) {
            unset($values[$key]);
        }

        foreach (self::MASKED as $key) {
            if (array_key_exists($key, $values) && $values[$key] !== null) {
                $values[$key] = self::mask((string) $values[$key]);
            }
        }

        return $values === [] ? null : $values;
    }

    /**
     * Păstrează ultimele 4 caractere (utile în suport: „se termină în ...4242"), maschează
     * restul. Un șir de 4 caractere sau mai scurt se maschează integral — nimic din el nu e
     * suficient de lung ca „ultimele 4" să mai însemne vreo protecție.
     */
    private static function mask(string $value): string
    {
        $length = strlen($value);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).substr($value, -4);
    }
}
