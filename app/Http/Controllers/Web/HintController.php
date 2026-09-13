<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * BR-HELP-02 — respingerea unui indiciu inline de primă vizită (ex: „psst, apasă
 * `?` pentru ajutor"). Persistat pe `users.dismissed_hints` (jsonb, cast
 * `array`): PER UTILIZATOR, nu per tenant — identitatea e globală (§19.1), la
 * fel ca `users.theme` (FR-PREF-02), deci un indiciu respins pe workspace-ul
 * „marlin" rămâne respins și pe „cascade".
 *
 * Ruta n-are `{workspace}` în cale (routes/web/hints.php, inclusă din grupul
 * `auth + session.context`, ÎNAINTE de grupul cu workspace din routes/web.php)
 * — corect, din moment ce nu e o preferință de tenant.
 */
class HintController extends Controller
{
    /**
     * Idempotent: al doilea `POST` cu aceeași cheie (ex: două taburi deschise) nu
     * produce o a doua intrare și nu eșuează — pur și simplu nu schimbă nimic.
     *
     * Validare strict server-side, NU doar la nivel de rută: o constrângere pe
     * rută ar întoarce 404 pentru o cheie nevalidă, ceea ce ascunde diferența
     * dintre „ruta nu există" și „cheia e greșit formată" — al doilea caz merită
     * un 422 explicit.
     */
    public function dismiss(Request $request, string $key): RedirectResponse
    {
        $validated = Validator::make(
            ['key' => $key],
            ['key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/']],
        )->validate();

        /** @var User $user */
        $user = $request->user();

        $dismissed = $user->dismissed_hints ?? [];

        if (! in_array($validated['key'], $dismissed, true)) {
            $dismissed[] = $validated['key'];
            $user->forceFill(['dismissed_hints' => array_values($dismissed)])->save();
        }

        return back();
    }
}
