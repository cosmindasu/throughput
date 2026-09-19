<?php

namespace App\Support\Mail;

/**
 * BR-DEMO-02, specs.md §22.3 — potrivirea „adresă în lista albă", pe domeniu ȘI pe adresă
 * exactă, insensibil la majuscule.
 *
 * Citește `config('throughput.demo.email_allowlist')` LA FIECARE apel, fără cache în
 * proprietăți statice: transportul rulează în worker-ul de coadă, un proces cu viață lungă
 * care servește tenanți/joburi diferite — „Memoizarea per cerere" din `.ai/rules/tenancy.md`.
 * Config-ul e deja un array parsat o singură dată de framework (`config:cache` în producție),
 * deci re-citirea de aici nu costă un round-trip nou — doar evită o a doua stare de cache,
 * a noastră, care ar putea rămâne în urma unui `config:clear`/schimbare de mediu între
 * joburi din același proces.
 *
 * Format acceptat, per intrare din `DEMO_EMAIL_ALLOWLIST` (listă separată prin virgulă,
 * parsată deja în `config/throughput.php`):
 *   - `exemplu.com` sau `@exemplu.com` — potrivește ORICE adresă de pe acel domeniu.
 *   - `nume@exemplu.com` — potrivește DOAR acea adresă exactă.
 *
 * O listă GOALĂ înseamnă „interceptează tot", NU „livrează tot" (capcana semnalată explicit
 * în mandatul pachetului): bucla de mai jos pur și simplu nu găsește nicio potrivire pentru
 * nicio adresă când lista e goală, deci `isAllowed()` întoarce `false` implicit — nu există
 * o ramură specială „listă goală → true" de uitat de scris sau de inversat din greșeală.
 */
final class DemoEmailAllowlist
{
    public static function isAllowed(string $address): bool
    {
        $address = mb_strtolower(trim($address));

        if ($address === '') {
            return false;
        }

        $domain = self::domainOf($address);

        foreach (self::entries() as $entry) {
            if ($entry === $address) {
                return true;
            }

            if ($domain !== null && $entry === $domain) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function entries(): array
    {
        /** @var list<string> $configured */
        $configured = config('throughput.demo.email_allowlist', []);

        return array_values(array_map(
            static fn (string $entry): string => ltrim(mb_strtolower(trim($entry)), '@'),
            $configured,
        ));
    }

    private static function domainOf(string $address): ?string
    {
        $at = strrpos($address, '@');

        return $at === false ? null : mb_substr($address, $at + 1);
    }
}
