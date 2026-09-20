<?php

namespace App\Http\Controllers\Web\Invoices;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Support\Exports\ExportFormat;
use App\Support\Exports\ListExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * FR-BILL-03 — „Export facturi în masă (PDF zip sau CSV sumar)", prin mecanismul din §13.2
 * (`ListExport`), pe exact interogarea ecranului curent. Un singur `__invoke`, ca
 * `AccountLookupController`: o acțiune, un fișier.
 *
 * AUTORIZARE — `InvoicePolicy::export()`, aceeași formă ca `OrderPolicy::export()` și
 * `AccountPolicy::export()`. Lotul a scris regula inline aici, fiindcă Policy-ul era fișier
 * comun cât a ținut paralelismul; la integrare a fost mutată în Policy, unde stau celelalte.
 * Regula e neschimbată: `invoices.view` + `bulk.export`.
 *
 * VIEWER-UL EXPORTĂ (BR-BULK-03, §7.4 nota ³): exportul e o CITIRE a rândurilor deja
 * vizibile pe ecran, livrată ca fișier — un refuz n-ar proteja nimic, iar persona
 * „contabil extern" (§5) există exact pentru fluxul ăsta. Contenția vine din limita de 3
 * operații concurente per utilizator (§22.5, `BulkConcurrencyGuard`, verificată în
 * `ListExport`), identică pentru toate rolurile. Îngustarea pe proprietate pentru Agent nu
 * se scrie aici: `InvoiceList::applyFilters()` o aplică deja pentru ORICE consumator al
 * listei, inclusiv exportul — un al doilea loc ar putea diverge.
 */
final class InvoiceExportController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        abort_unless(
            $request->user()->can('export', Invoice::class),
            403,
        );

        return app(ListExport::class)->respond($request, 'invoices', ExportFormat::fromRequest($request));
    }
}
