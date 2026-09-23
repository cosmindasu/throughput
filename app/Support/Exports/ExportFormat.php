<?php

namespace App\Support\Exports;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Formatul unui export de listă (§13.5) — sursă unică pentru „ce valori sunt valide",
 * folosită de `OrderController::export()` azi și, fără nicio copie, de orice alt export
 * care ajunge să suporte mai mult decât CSV (Accounts, Contacts — code review P2:
 * validarea trebuia să stea într-un singur loc, nu repetată per controller).
 */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Pdf = 'pdf';

    /**
     * FR-BILL-03 — „PDF zip sau CSV sumar" pentru facturi (§13.5, rândul „Facturi"). NU e
     * „PDF-ul listei, arhivat": e arhiva PDF-urilor DEJA GENERATE, câte unul per factură
     * (`App\Jobs\Invoices\GenerateInvoicePdfJob`). De-asta e un format propriu și nu o
     * opțiune a lui `Pdf` — sursa fișierelor e alta.
     *
     * Disponibil doar pe listele care implementează `ArchivableList`; `ListExport` refuză
     * explicit orice altă resursă cerută ca `zip`, în loc să producă o arhivă goală.
     */
    case Zip = 'zip';

    /**
     * `format` ABSENT din cerere → CSV (comportamentul de azi, neschimbat pentru
     * Accounts/Contacts, care nu trimit deloc acest parametru). Orice altă valoare
     * NECUNOSCUTĂ (`xlsx`, `PDF` cu majusculă etc.) → 422 explicit — NU o cădere tăcută pe
     * CSV (code review P2: un export cerut ca PDF nu are voie să se descarce, tăcut, ca
     * CSV, doar pentru că valoarea din URL era greșit scrisă).
     *
     * `abort(422, ...)` direct, nu `ValidationException` — un `GET` simplu (linkul de
     * export e o ancoră `<a href>`, nu o cerere Inertia/JSON) n-ar primi automat 422 din
     * `ValidationException` (Laravel redirects la 302 cu erori de sesiune când cererea nu
     * „expectsJson()"); `abort()` dă STATUSUL cerut, indiferent de headerele `Accept`.
     */
    public static function fromRequest(Request $request): self
    {
        $raw = $request->query('format');

        if ($raw === null) {
            return self::Csv;
        }

        return self::tryFrom($raw)
            // I18N-08, FR-I18N-04 — mutat din literal englez direct în excepție. `:format`
            // e valoarea BRUTĂ a query string-ului (`$raw`, conținut de utilizator, nu o
            // etichetă a aplicației) — ghilimelele rămân drepte (" ") în ambele limbi,
            // deliberat, nu « » franceze: FR-I18N-06, aceeași distincție documentată în
            // `lang/fr/imports.php` (ghilimele franceze doar pe etichete DECISE de aplicație).
            ?? throw new HttpException(422, __('exports.errors.unknown_format', ['format' => $raw]));
    }
}
