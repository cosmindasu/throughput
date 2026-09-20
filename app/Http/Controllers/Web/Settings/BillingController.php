<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Billing\SubscriptionAccessPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `Settings/Billing` — FR-BILL-01, Owner-only (§7.4: „Abonament & billing Throughput" —
 * CRUD doar Owner, `—` pentru restul; §7.3 criteriul de acceptanță: Managerul nu vede nici
 * măcar opțiunea). `billing.view`/`billing.manage` EXISTĂ deja în
 * `App\Support\Permissions` (nu modificat aici — instrucțiune explicită a lotului).
 *
 * Scop DELIBERAT îngustat la ce cere bulletul lotului: „planul curent + link către Stripe
 * Customer Portal" — nu construiește Stripe Checkout pentru schimbarea de plan (US-BILL-03
 * nu e în lista de bullet-uri primită). „Reactivate" (stare `canceled`) redirectă la
 * ACELAȘI Customer Portal — Stripe poate configura portalul să permită re-abonarea; o
 * reactivare „proprie", cu Checkout dedicat pentru un abonament ajuns `canceled` prin
 * epuizarea reîncercărilor, ar fi un flux nou, în afara scopului primit (semnalat în
 * raport, nu implementat tacit).
 */
final class BillingController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeBillingView($request);

        $tenant = app('tenant');
        $subscription = $tenant->subscription();
        $accessLevel = SubscriptionAccessPolicy::levelFor($tenant);

        return Inertia::render('Settings/Billing/Index', [
            'subscription' => [
                'status' => $subscription?->stripe_status,
                'plan' => $subscription?->stripe_price,
                'paymentMethod' => $tenant->pm_type !== null
                    ? ['type' => $tenant->pm_type, 'lastFour' => $tenant->pm_last_four]
                    : null,
                'canceledAt' => $tenant->subscription_canceled_at?->toIso8601String(),
                'accessLevel' => $accessLevel->value,
            ],
            // FR-BILL-01 — „istoric de facturi", cerință explicită, nu opțională (review-ul
            // lotului, pct. 2). Cashier le expune deja pe `Billable`; NU comutăm renderer-ul
            // de PDF (ADR-021) — orice descărcare rămâne pe implicitul Cashier, DomPDF.
            'invoices' => $this->invoiceHistory($tenant),
            'can' => [
                'manage' => $request->user()->can('billing.manage'),
            ],
        ]);
    }

    /**
     * A DOUA (și ultima) abatere conștientă de ADR-013 din acest lot, din același motiv
     * structural ca `portal()`: „istoricul de facturi" cere lista REALĂ de la Stripe, nu
     * ceva stocat local — nu există echivalent async rezonabil pentru randarea UNEI pagini
     * de citire. Cashier însuși garantează absența oricărui apel dacă tenantul n-are încă
     * `stripe_id` (`ManagesInvoices::invoices()` verifică `hasStripeId()` ÎNAINTE de orice
     * cerere către Stripe) — exact cazul tenanților de test din acest lot, care nu ating
     * niciodată rețeaua. `try/catch` în plus, pentru tenanți CU `stripe_id`: un hop Stripe
     * căzut nu are voie să transforme pagina de billing într-un 500 — se arată „momentan
     * indisponibil", nu o eroare necontrolată.
     *
     * @return list<array{id: string, date: ?string, total: string, status: ?string, hostedUrl: ?string}>
     */
    private function invoiceHistory(Tenant $tenant): array
    {
        try {
            return $tenant->invoicesIncludingPending()
                ->map(fn ($invoice) => [
                    'id' => $invoice->id,
                    'date' => $invoice->date()?->toIso8601String(),
                    'total' => $invoice->total(),
                    'status' => $invoice->status ?? null,
                    'hostedUrl' => $invoice->hosted_invoice_url ?? null,
                ])
                ->all();
        } catch (Throwable $e) {
            report($e);
            Log::warning('Nu s-a putut încărca istoricul de facturi de la Stripe.', [
                'tenant_id' => $tenant->getKey(),
            ]);

            return [];
        }
    }

    /**
     * Abatere CONȘTIENTĂ de ADR-013 („niciun apel extern în cererea HTTP") — singura din
     * acest lot. Motivul e structural, nu neglijență: crearea unei sesiuni de Stripe
     * Billing Portal n-are echivalent asincron — browserul are nevoie de URL-ul întors DE
     * STRIPE ca să redirecteze IMEDIAT, nu există un „pending" de afișat cu polling, ca la
     * eticheta de curierat sau la PDF-ul de factură. Apelul e scurt (un singur POST către
     * Stripe, fără upload/randare), spre deosebire de Chromium-ul de câteva secunde care a
     * motivat ADR-013 inițial. Semnalat explicit în raportul lotului, nu ascuns în cod.
     *
     * `Inertia::location()`, NU `redirect()`: ținta e alt domeniu (`checkout.stripe.com`/
     * `billing.stripe.com`) — clientul Inertia ar încerca să urmeze un `redirect()` normal
     * ca pe o vizită SPA (XHR), care fie eșuează la CORS, fie randează HTML-ul Stripe ca
     * „pagină Inertia". `Inertia::location()` forțează un `window.location` complet.
     */
    public function portal(Request $request): Response
    {
        $this->authorizeBillingManage($request);

        $tenant = app('tenant');

        $url = $tenant->billingPortalUrl(route('settings.billing.index'));

        return Inertia::location($url);
    }

    private function authorizeBillingView(Request $request): void
    {
        abort_unless($request->user()->can('billing.view'), 403);
    }

    private function authorizeBillingManage(Request $request): void
    {
        abort_unless($request->user()->can('billing.manage'), 403);
    }
}
