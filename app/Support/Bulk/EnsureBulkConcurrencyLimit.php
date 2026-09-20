<?php

namespace App\Support\Bulk;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * §22.5 — poarta de intrare a limitei de 3 operații în masă concurente per utilizator, pe
 * rutele de SCRIERE în masă (`routes/web/bulk.php`). Exporturile își verifică aceeași limită
 * în `App\Support\Exports\ListExport`, unde se decide dacă operația chiar devine un rând în
 * coadă (sub pragul sincron, un CSV nu e „o operație": se construiește în cerere și nu ocupă
 * nimic).
 *
 * DE CE LA NIVEL DE CERERE, și nu la crearea fiecărui rând `bulk_operations`: BR-TEN-06
 * („Reassign and deactivate") creează DELIBERAT trei rânduri într-o singură acțiune de
 * utilizator, legate prin `group_id` (BR-BULK-04). Verificat per rând, al doilea și al
 * treilea ar fi fost refuzate de propria lor operație — un singur clic ar fi eșuat pe
 * jumătate. Verificat pe cerere, utilizatorul pornește o acțiune și ajunge la 3 operații
 * active, exact ce descrie §22.5.
 *
 * DE CE STĂ ÎN `App\Support\Bulk` și nu în `App\Http\Middleware`: disciplina de fișiere a
 * lotului (`app/Http/Middleware` e teritoriu de integrare în acest val). Laravel nu cere ca
 * un middleware de rută să trăiască într-un director anume — mutarea e o redenumire de
 * namespace, semnalată în raport.
 *
 * `ValidationException` cu cheia `selection`, nu `abort(429)`: e exact cheia pe care o
 * folosește deja `App\Actions\Bulk\DispatchBulkOperationAction` pentru celelalte trei refuzuri
 * ale aceluiași formular (plafon de rol, plafon DEMO_MODE, prag de confirmare), deci bara de
 * selecție afișează mesajul fără nicio schimbare de client.
 */
class EnsureBulkConcurrencyLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && BulkConcurrencyGuard::hasReachedLimit($user)) {
            throw ValidationException::withMessages(['selection' => BulkConcurrencyGuard::refusal()]);
        }

        return $next($request);
    }
}
