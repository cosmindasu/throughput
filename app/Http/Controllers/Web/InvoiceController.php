<?php

namespace App\Http\Controllers\Web;

use App\Actions\Invoices\CreateInvoiceAction;
use App\Actions\Invoices\MarkInvoiceSentAction;
use App\Actions\Invoices\VoidInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\VoidInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Jobs\Invoices\GenerateInvoicePdfJob;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Support\Lists\CursorPage;
use App\Support\Lists\InvoiceList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Facturare către clienți — AR intern, decuplat de Stripe (ADR-005), specs.md §12.1,
 * plan §11. Controller subțire: numerotarea/tranzițiile trăiesc în `App\Actions\Invoices`,
 * la fel cum `OrderController` deleagă la `App\Actions\Orders`.
 */
final class InvoiceController extends Controller
{
    public function index(Request $request, InvoiceList $list): Response
    {
        Gate::authorize('viewAny', Invoice::class);

        $query = $list->parse($request);
        $paginator = $query->paginate($list->query($query, $request->user()));

        return Inertia::render('Invoices/Index', [
            'invoices' => Inertia::defer(fn () => CursorPage::make($paginator, InvoiceResource::class)),
            'filters' => $query->toArray(),
            'can' => [
                // FR-BILL-03 (Faza 5, valul 2) — sursa butoanelor „Export CSV"/„Export PDF
                // (zip)". Aceeași expresie ca `App\Http\Controllers\Web\Invoices\
                // InvoiceExportController`, singurul loc care o aplică server-side.
                // Separată de drepturile de scriere (§7.4 nota ³, BR-BULK-03): exportul e o
                // citire, permisă și Viewer-ului.
                'export' => $request->user()->can('export', Invoice::class),
            ],
        ]);
    }

    public function show(Invoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        $invoice->load([
            'order:id,order_number,account_id,owner_user_id',
            'order.account:id,name',
            'order.owner:id,name',
            // `latest('id')` ca departajare: `payments.paid_at` e `timestamp(0)`, iar două
            // încasări PARȚIALE pe aceeași factură pot cădea în aceeași secundă — atunci ordinea
            // afișată nu mai e garantată. ULID-urile cresc monoton la inserare.
            'payments' => fn ($query) => $query->latest('paid_at')->latest('id'),
            'payments.createdBy:id,name',
        ]);

        return Inertia::render('Invoices/Show', [
            'invoice' => InvoiceResource::make($invoice),
        ]);
    }

    /**
     * US-BILL-01 — „Create Invoice" dintr-o comandă `confirmed`/`fulfilled`, apelat din
     * secțiunea de facturare a `Orders/Show.tsx`. Redirecționează pe pagina facturii noi,
     * ca „Mark as sent" din Gherkin să fie pasul imediat următor.
     */
    public function store(Order $order, CreateInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('create', [Invoice::class, $order]);

        $invoice = $action->execute($order);

        return redirect()->route('invoices.show', $invoice)->with('success', __('flash.invoices.created'));
    }

    public function markSent(Invoice $invoice, MarkInvoiceSentAction $action): RedirectResponse
    {
        Gate::authorize('update', $invoice);

        $sent = $action->execute($invoice);

        return redirect()->route('invoices.show', $sent)->with('success', __('flash.invoices.marked_sent'));
    }

    public function void(VoidInvoiceRequest $request, Invoice $invoice, VoidInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('void', $invoice);

        $voided = $action->execute($invoice, $request->string('reason')->toString());

        return redirect()->route('invoices.show', $voided)->with('success', __('flash.invoices.voided'));
    }

    /**
     * Butonul de descărcare e activ DOAR pe `pdf_status = ready` (§12.1) — UI-ul ascunde
     * linkul altfel, dar verificat și aici, server-side: un link vechi/copiat pentru o
     * factură încă `pending`/`failed` nu are ce descărca.
     */
    public function downloadPdf(Invoice $invoice): StreamedResponse|RedirectResponse
    {
        Gate::authorize('view', $invoice);

        if ($invoice->pdf_status !== Invoice::PDF_STATUS_READY || $invoice->pdf_path === null) {
            return redirect()->route('invoices.show', $invoice)->with('error', __('flash.invoices.pdf_not_ready'));
        }

        return Storage::disk('local')->download($invoice->pdf_path, "{$invoice->invoice_number}.pdf");
    }

    /**
     * „pe failed se afișează motivul și un buton de reîncercare" (§12.1) — resetează
     * `pdf_status` la `pending` și redispecerizează exact jobul care a eșuat.
     */
    public function retryPdf(Invoice $invoice): RedirectResponse
    {
        Gate::authorize('retryPdf', $invoice);

        // Idempotent, ca `GenerateShippingLabelJob`: un al doilea „Retry" pe o factură
        // deja `pending`/`ready` nu redispecerizează un al doilea job și nu arată un
        // mesaj de succes fals.
        if ($invoice->pdf_status !== Invoice::PDF_STATUS_FAILED) {
            return redirect()->route('invoices.show', $invoice);
        }

        $invoice->update(['pdf_status' => Invoice::PDF_STATUS_PENDING]);
        GenerateInvoicePdfJob::dispatch(TenantScope::requireCurrentTenantId(), $invoice->getKey());

        return redirect()->route('invoices.show', $invoice)->with('success', __('flash.invoices.regenerating_pdf'));
    }

    /**
     * Endpoint JSON (nu props Inertia), consumat de `BillingSection` de pe
     * `Orders/Show.tsx` — la fel ca `VariantLookupController`. Motivul de a nu fi un
     * prop Inertia obișnuit: `App\Http\Controllers\Web\Orders\OrderController` și
     * `App\Http\Resources\Orders\OrderResource` NU sunt fișiere ale acestui lot (owner-ul
     * planului le-a listat separat, deliberat, ca fișiere „ale altcuiva" — vezi raportul
     * livrat, secțiunea CONTRAZICERI/limitări), deci secțiunea de facturare de pe pagina
     * comenzii nu poate primi datele ca prop din acel controller fără să-l editez. Un
     * fetch propriu, mic, păstrează granița de proprietate a fișierelor intactă.
     */
    public function forOrder(Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        // TEST-01 (audit 2026-09-23) — folosea o interogare manuală cu `->latest('created_at')`,
        // fără tiebreaker pe `id`: exact bugul deja reparat o dată în `Order::invoice()`
        // (`.ai/rules/tenancy.md`, „created_at are precizie 0"). O anulare + reemitere în
        // aceeași secundă putea întoarce factura ANULATĂ pe acest endpoint, care alimentează
        // `BillingSection` de pe `Orders/Show.tsx`. `$order->invoice` e relația deja corectă
        // (`latestOfMany(['created_at', 'id'])`) — nu o duplica aici.
        $invoice = $order->invoice;

        // SCURGERE reparată (audit 2026-10-06, a doua trecere peste masca de la 2026-10-05).
        // `Gate::authorize('view', $order)` de mai sus e autorizarea pe COMANDĂ, iar
        // `OrderPolicy::view()` e `$user->can('orders.view')` CURAT — fără îngustare pe
        // proprietar, deliberat (§7.4: un Agent poate deschide orice comandă din workspace).
        // `InvoicePolicy::view()` NU e la fel: cere `invoices.view` ȘI `isWithinOwnRecords()`.
        // Deci endpoint-ul ăsta întorcea `invoice_number`, `status` și `balance_due` ale
        // facturii de pe comanda unui coleg oricărui Agent care deschidea pagina comenzii —
        // un canal mai direct decât jurnalul de activitate, pe care lotul precedent îl
        // închisese (`ActivityVisibility`). O mască pe un singur canal nu e mască.
        //
        // Se întoarce `null`, NU o formă mascată: Agentul n-are `invoices.create`
        // (`Permissions.php`), deci `can.create` iese `false` și secțiunea de facturare nu se
        // randează deloc — absență, nu un rând care spune „există o factură, dar nu ți-o
        // arăt". E aceeași regulă pe care o enunță restul produsului: fără drept, fără buton,
        // nu buton dezactivat. Owner/Manager/Viewer nu sunt atinși: `restrictedToOwnRecords`
        // e adevărat doar pentru Agent, iar Viewer-ul are `invoices.view` pe tot workspace-ul.
        if ($invoice !== null && ! Gate::allows('view', $invoice)) {
            $invoice = null;
        }

        return response()->json([
            'can' => [
                'create' => Gate::allows('create', [Invoice::class, $order]),
            ],
            'invoice' => $invoice === null ? null : [
                'id' => $invoice->id,
                'invoiceNumber' => $invoice->invoice_number,
                'status' => $invoice->status,
                'balanceDue' => (float) $invoice->balance_due,
                'currency' => $invoice->currency,
            ],
        ]);
    }
}
