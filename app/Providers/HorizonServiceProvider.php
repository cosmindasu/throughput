<?php

namespace App\Providers;

use App\Http\Middleware\HorizonBasicAuth;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * OPS-03 — echivalentul lui `php artisan horizon:install`, scris de mână: comanda ar fi
 * republicat și `config/horizon.php`, care are deja plafoanele de memorie măsurate (plan §3.1).
 *
 * Nu definește gate-ul `viewHorizon` pe e-mail, cum face scheletul pachetului — motivul e în
 * `HorizonBasicAuth`: niciun utilizator din baza de date nu e o identitate stabilă aici.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function authorization(): void
    {
        Horizon::auth(fn (Request $request): bool => HorizonBasicAuth::passes($request));
    }
}
