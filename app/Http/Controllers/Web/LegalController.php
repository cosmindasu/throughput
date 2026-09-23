<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GDPR-06 (audit 2026-09-23, §3, Art. 13/14) — suprafață publică minimă de conformitate:
 * Politica de confidențialitate și Termenii de utilizare. Rute FIXE (`routes/web.php`),
 * accesibile anonim și autentificat, fără workspace în cale — de aici controllerul propriu,
 * separat de orice modul de business, cu doar două acțiuni fără parametri.
 *
 * Tot textul trăiește în catalogul `legal` (`resources/js/locales/{en,fr}/legal.json`);
 * paginile React nu primesc niciun prop dinamic — conținutul e static, ca la orice pagină
 * legală minimă.
 */
class LegalController extends Controller
{
    public function privacy(): Response
    {
        return Inertia::render('Legal/Privacy');
    }

    public function terms(): Response
    {
        return Inertia::render('Legal/Terms');
    }
}
