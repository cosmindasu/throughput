<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * I18N-03 — codificarea `error_message` (coloană `text`, nullable) de pe `bulk_operations`,
 * `shipments`, `report_runs`, `data_export_requests`. Cele patru joburi care scriu coloana
 * rulează pe un WORKER de coadă, fără cererea HTTP a nimănui (`.ai/rules/tenancy.md`, „două
 * familii de joburi") — un literal englez scris acolo, direct, e opac pentru
 * `php artisan i18n:coverage` (n-are nicio cheie de comparat) și, mai rău, chiar dacă ar fi
 * fost `trans()`-uit la scriere, ar fi înghețat în limba locale-ului WORKER-ULUI din acel
 * moment, nu a cererii care randează mai târziu ecranul — exact capcana de „memoizare peste
 * viața lungă a workerului" descrisă în `.ai/rules/tenancy.md:123-138`, doar pe limbă în loc
 * de tenant.
 *
 * Tiparul de aici e cel al `App\Support\Activity\ActivityActionLabel`, extins cu parametri:
 * jobul NU traduce — scrie o cheie de catalog (`lang/{en,fr}/job_errors.php`) plus
 * parametrii ei brut, iar traducerea se întâmplă abia în `Resource::toArray()`, în request-ul
 * care randează ecranul, cu locale-ul ACELEI cereri (`App::getLocale()` la acel moment).
 *
 * Format stocat — JSON simplu în coloana `text` existentă, fără nicio migrație:
 * `{"key":"job_errors.bulk.initiator_gone","params":{...}}`.
 *
 * **Parametri care sunt ei înșiși etichete traduse** (ex: statusul unei comenzi,
 * `App\Enums\OrderStatus::label()`) NU se rezolvă la scriere — jobul n-are cererea al cărei
 * locale ar trebui folosit, doar pe-al lui (dacă are unul deloc). Se stochează VALOAREA BRUTĂ
 * a enum-ului (`$order->status->value`), înfășurată prin `translatedParam()` cu cheia de
 * catalog corespunzătoare (`enums.order_status.confirmed`) — `render()` o traduce abia la
 * citire. Un parametru „tradus" la scriere ar îngheța limba jobului în coloană, la fel ca
 * mesajul întreg.
 *
 * `render()` e TOLERANT prin construcție, cu două căi de întoarcere neschimbată, nu de
 * eroare:
 *  - rânduri VECHI, scrise înainte de acest lot — text englez simplu, nu JSON — nu se pot
 *    decoda ca array cu o cheie `key` validă, deci se întorc EXACT cum sunt;
 *  - mesaje de la un FURNIZOR EXTERN (ex. `ShippingLabelFailed`, textul EXACT raportat de un
 *    transportator — vezi `App\Services\Shipping\ShippingCarrier`) — text dinamic,
 *    imposibil de catalogat static, deliberat NECODIFICAT de apelant — cad pe aceeași cale.
 * O cheie codificată dar NECUNOSCUTĂ catalogului (`Lang::has()` fals — catalog desincronizat,
 * cheie redenumită) ia aceeași cale: informația brută rămâne vizibilă (JSON-ul stocat), în
 * loc să arunce sau să dispară.
 */
final class JobErrorMessage
{
    /**
     * Cheile interne ale unui parametru „etichetă tradusă" (vezi docblock-ul clasei).
     * Rezervat — un job nu trebuie să scrie manual un array cu această cheie decât prin
     * `translatedParam()`.
     */
    private const TRANSLATION_KEY_PARAM = '__translationKey';

    /**
     * @param  array<string, scalar|array{__translationKey: string}>  $params
     */
    public static function encode(string $key, array $params = []): string
    {
        return json_encode(['key' => $key, 'params' => $params]) ?: $key;
    }

    /**
     * Înfășoară o cheie de catalog ca parametru amânat — vezi „Parametri care sunt ei
     * înșiși etichete traduse" în docblock-ul clasei. Valoarea brută a enum-ului rămâne
     * doar în cheia de catalog (`enums.order_status.confirmed`), nu se duplică separat.
     *
     * @return array{__translationKey: string}
     */
    public static function translatedParam(string $translationKey): array
    {
        return [self::TRANSLATION_KEY_PARAM => $translationKey];
    }

    /**
     * `null`/`''` trec neschimbate — un shipment/export/raport fără eroare n-are ce
     * decodifica. Vezi docblock-ul clasei pentru cele două căi „neschimbat, nu eroare".
     */
    public static function render(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return $stored;
        }

        $decoded = json_decode($stored, true);

        if (! is_array($decoded) || ! isset($decoded['key']) || ! is_string($decoded['key'])) {
            return $stored;
        }

        $key = $decoded['key'];

        if (! Lang::has($key)) {
            return $stored;
        }

        $rawParams = $decoded['params'] ?? [];
        $params = is_array($rawParams) ? $rawParams : [];

        $resolved = [];

        foreach ($params as $paramKey => $value) {
            $resolved[$paramKey] = self::resolveParam($value);
        }

        return (string) __($key, $resolved);
    }

    private static function resolveParam(mixed $value): string|int|float
    {
        if (is_array($value) && isset($value[self::TRANSLATION_KEY_PARAM]) && is_string($value[self::TRANSLATION_KEY_PARAM])) {
            return (string) __($value[self::TRANSLATION_KEY_PARAM]);
        }

        if (is_scalar($value)) {
            return $value;
        }

        return '';
    }
}
