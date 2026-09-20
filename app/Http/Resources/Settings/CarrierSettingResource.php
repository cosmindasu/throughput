<?php

namespace App\Http\Resources\Settings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `Settings/Shipping` — un rând per furnizor CUNOSCUT (`App\Services\Shipping\CarrierCatalog`),
 * nu per rând `tenant_carrier_settings` existent: un tenant care n-a configurat încă
 * Shippo tot trebuie să-l vadă în listă, ca să-l poată activa.
 *
 * BR-AUD-01 — `credentials` nu ajunge NICIODATĂ aici, nici mascat parțial în variabile
 * intermediare: `CarrierSettingController` calculează `configured`/`credentialPreview`
 * direct din valoarea decriptată și trimite doar ultimele 4 caractere ale cheii (destul
 * ca un Owner să recunoască CE cheie e activă atunci când are mai multe rotații, prea
 * puțin ca să reconstituie secretul) — niciodată cheia întreagă, în niciun prop Inertia.
 *
 * `credentialError` (audit P2) — distinct de „not configured yet": populat doar când
 * rândul EXISTĂ dar `credentials` nu mai poate fi decriptat (rotație de `APP_KEY` fără
 * `APP_PREVIOUS_KEYS`), ca Owner-ul să nu creadă că n-a completat niciodată cheia.
 *
 * @param  array{provider: string, label: string, description: string, requiresApiKey: bool, isActive: bool, configured: bool, credentialPreview: ?string, credentialError: ?string}  $resource
 */
final class CarrierSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'provider' => $this->resource['provider'],
            'label' => $this->resource['label'],
            'description' => $this->resource['description'],
            'requiresApiKey' => $this->resource['requiresApiKey'],
            'isActive' => $this->resource['isActive'],
            'configured' => $this->resource['configured'],
            'credentialPreview' => $this->resource['credentialPreview'],
            'credentialError' => $this->resource['credentialError'],
        ];
    }
}
