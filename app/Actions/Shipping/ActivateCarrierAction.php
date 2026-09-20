<?php

namespace App\Actions\Shipping;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\TenantCarrierSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FR-ORD-06, BR-ORD-03 — ecranul Settings → Shipping cheamă exclusiv această clasă
 * pentru orice scriere: „exact un rând `is_active = true` per tenant", aplicat
 * server-side, în tranzacție, niciodată doar convenție de formular.
 *
 * Invarianta NU trăiește pe un singur rând din `tenant_carrier_settings` — trăiește PESTE
 * toate rândurile tenantului curent (cel mult 2 azi: `shippo`/`demo`, dar codul nu
 * presupune numărul). `.ai/rules/tenancy.md` („Blocarea unui rând părinte: FOR NO KEY
 * UPDATE, nu FOR UPDATE") acoperă exact acest tipar: o invariantă cross-row se
 * serializează blocând rândul PĂRINTE (`tenants`, care n-are RLS — ADR-014), nu un rând
 * copil oarecare care ar putea nici să nu existe încă (prima activare a unui furnizor
 * nou INSEREAZĂ rândul, nu-l actualizează). `FOR NO KEY UPDATE`, nu `FOR UPDATE`: nu
 * trebuie să blocăm restul scrierilor tenantului (o comandă confirmată în paralel etc.),
 * doar să serializăm ÎNTRE ELE cererile care ating `tenant_carrier_settings`.
 *
 * Audit de securitate P1 — al doilea strat al interdicției „exclusiv chei sandbox"
 * (FR-ORD-01/BR-DEMO-03; primul strat e regexul din `UpdateCarrierSettingRequest`).
 * Ales AICI, nu în `CarrierResolver`: o cheie live nu trebuie doar refuzată LA FOLOSIRE
 * (când jobul de etichetă ar face deja un apel real către Shippo), ci refuzată la
 * SCRIERE — niciodată persistată, nici măcar criptat. Azi acesta e singurul loc din
 * aplicație care scrie `tenant_carrier_settings` din input de utilizator (seed-ul,
 * `Database\Seeders\Demo\CarrierSettingsSeeder`, e o sursă separată, controlată de
 * operator prin `SHIPPO_SANDBOX_KEY`, nu de un vizitator). Dacă un lot viitor adaugă o a
 * doua cale de scriere (API public, import), aceeași verificare se mută/duplică acolo —
 * nu e nevoie de ea și în `CarrierResolver` cât timp scrierea rămâne unică.
 */
final class ActivateCarrierAction
{
    private const SHIPPO_SANDBOX_KEY_PATTERN = '/^shippo_test_/';

    /**
     * @param  array<string, mixed>|null  $credentials  `null` = păstrează credențialele
     *                                                  existente ale acestui furnizor
     *                                                  (formularul mascat nu retrimite
     *                                                  o cheie neschimbată — vezi
     *                                                  `UpdateCarrierSettingRequest`).
     */
    public function execute(string $provider, ?array $credentials): TenantCarrierSetting
    {
        return DB::transaction(function () use ($provider, $credentials): TenantCarrierSetting {
            $tenantId = TenantScope::requireCurrentTenantId();

            // Rândul PĂRINTE, NU un rând din `tenant_carrier_settings` — vezi docblock-ul
            // clasei. `tenants` n-are RLS (ADR-014), deci acest SELECT nu are nevoie de
            // nimic în plus față de contextul deja setat de cerere.
            Tenant::query()->whereKey($tenantId)->lock('for no key update')->firstOrFail();

            $existing = TenantCarrierSetting::query()->where('provider', $provider)->first();

            // P3 (audit) — whitelist explicit, nu doar ce a trimis formularul azi:
            // `credentials` e `jsonb` criptat fără schemă aplicată de DB, deci o sub-cheie
            // în plus (trimisă din greșeală sau din malițiozitate) s-ar salva altfel
            // criptată, dar necontrolată — inertă azi, un risc tăcut mâine, la primul cod
            // care ar citi-o fără să verifice ce conține.
            $mergedCredentials = Arr::only($credentials ?? ($existing->credentials ?? []), ['api_key']);

            if ($provider === 'shippo') {
                $apiKey = $this->apiKey($mergedCredentials);

                if ($apiKey === null) {
                    throw ValidationException::withMessages([
                        'credentials.api_key' => 'Add a Shippo API key before activating this provider.',
                    ]);
                }

                if (! preg_match(self::SHIPPO_SANDBOX_KEY_PATTERN, $apiKey)) {
                    throw ValidationException::withMessages([
                        'credentials.api_key' => 'Only Shippo sandbox keys (shippo_test_...) are accepted in this deployment — never a live key.',
                    ]);
                }

                // Persistă valoarea CURĂȚATĂ (`trim()`-uită la validare), nu ce a trimis
                // brut formularul — altfel un spațiu accidental la capăt ar trece de
                // validare aici, dar ar rămâne pe rând.
                $mergedCredentials['api_key'] = $apiKey;
            }

            $setting = TenantCarrierSetting::query()->updateOrCreate(
                ['provider' => $provider],
                ['credentials' => $mergedCredentials, 'is_active' => true],
            );

            // BR-ORD-03 — „schimbarea furnizorului activ dezactivează automat cel
            // anterior, în ACEEAȘI tranzacție": sub blocarea de mai sus, nicio a doua
            // cerere nu poate intercala o scriere între aceste două instrucțiuni.
            TenantCarrierSetting::query()->whereKeyNot($setting->getKey())->update(['is_active' => false]);

            return $setting->refresh();
        });
    }

    /**
     * `null` dacă lipsește SAU e doar spații — un string „ " ar trece testul vechi
     * `!== ''` și ar activa furnizorul cu o cheie inutilizabilă, descoperită abia la
     * primul shipment (audit P3).
     *
     * @param  array<string, mixed>  $credentials
     */
    private function apiKey(array $credentials): ?string
    {
        $apiKey = $credentials['api_key'] ?? null;

        if (! is_string($apiKey)) {
            return null;
        }

        $apiKey = trim($apiKey);

        return $apiKey === '' ? null : $apiKey;
    }
}
