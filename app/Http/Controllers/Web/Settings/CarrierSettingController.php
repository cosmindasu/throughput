<?php

namespace App\Http\Controllers\Web\Settings;

use App\Actions\Shipping\ActivateCarrierAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCarrierSettingRequest;
use App\Http\Resources\Settings\CarrierSettingResource;
use App\Models\TenantCarrierSetting;
use App\Services\Shipping\CarrierCatalog;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Shipping (FR-ORD-06, §7.4 „Setări curierat") — Owner-only, ADR-010.
 * Controller-ul rămâne SUBȚIRE: forma payload-ului e verificată de
 * `UpdateCarrierSettingRequest`, invarianta BR-ORD-03 de `ActivateCarrierAction`.
 */
final class CarrierSettingController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TenantCarrierSetting::class);

        $existingByProvider = TenantCarrierSetting::query()->get()->keyBy('provider');

        $providers = collect(CarrierCatalog::all())
            ->map(function (array $meta, string $provider) use ($existingByProvider): CarrierSettingResource {
                $setting = $existingByProvider->get($provider);
                [$apiKey, $credentialError] = $this->apiKeyAndError($setting);
                $hasApiKey = $apiKey !== null;

                return new CarrierSettingResource([
                    'provider' => $provider,
                    'label' => $meta['label'],
                    'description' => $meta['description'],
                    'requiresApiKey' => $meta['requiresApiKey'],
                    'isActive' => (bool) $setting?->is_active,
                    'configured' => ! $meta['requiresApiKey'] || $hasApiKey,
                    // Ultimele 4 caractere — destul ca un Owner să distingă între două
                    // rotații ale aceleiași chei, prea puțin ca să reconstituie secretul
                    // (vezi docblock-ul `CarrierSettingResource`).
                    'credentialPreview' => $hasApiKey ? '•••• '.substr($apiKey, -4) : null,
                    'credentialError' => $credentialError,
                ]);
            })
            ->values();

        return Inertia::render('Settings/Shipping/Index', [
            'providers' => $providers,
            'can' => [
                'manage' => $request->user()->can('manage', TenantCarrierSetting::class),
            ],
        ]);
    }

    /**
     * Audit de securitate P2 — o rotație de `APP_KEY` fără `APP_PREVIOUS_KEYS` face
     * `credentials` (cast `encrypted:array`) irecuperabil: `DecryptException: The MAC is
     * invalid`. `GenerateShippingLabelJob` degradează deja corect (eroare internă,
     * `label_failed`, generic pe shipment) — ecranul arunca 500 pe simpla încărcare a
     * paginii. Aici tratat ca „neconfigurat", cu un mesaj DISTINCT de „No API key
     * configured yet" (altfel Owner-ul crede că n-a completat niciodată cheia, nu că nu
     * mai poate fi citită) — procedura de rotație propriu-zisă rămâne documentată separat.
     *
     * @return array{0: ?string, 1: ?string} [cheia validă sau `null`, mesaj de eroare sau `null`]
     */
    private function apiKeyAndError(?TenantCarrierSetting $setting): array
    {
        if ($setting === null) {
            return [null, null];
        }

        try {
            $apiKey = $setting->credentials['api_key'] ?? null;

            return [is_string($apiKey) && $apiKey !== '' ? $apiKey : null, null];
        } catch (DecryptException $e) {
            report($e);

            return [null, 'Stored credentials could not be read (the encryption key may have changed) — re-enter the API key.'];
        }
    }

    public function update(UpdateCarrierSettingRequest $request, ActivateCarrierAction $action): RedirectResponse
    {
        $action->execute($request->providerInput(), $request->credentialsInput());

        return redirect()->route('settings.shipping.index')->with('success', __('flash.carrier_settings.updated'));
    }
}
