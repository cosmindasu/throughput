<?php

namespace App\Http\Middleware;

use App\Enums\SubscriptionAccessLevel;
use App\Support\Billing\SubscriptionAccessPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * specs.md §12.2 — modelul de degradare pe 3 trepte, aplicat și pe API-ul public.
 *
 * ## De ce un middleware NOU și nu `EnsureSubscriptionAccess`
 *
 * Regula de business e IDENTICĂ și se citește din același loc — `SubscriptionAccessPolicy`
 * (BR-BILL-04, „un singur loc întoarce nivelul"). Ce diferă e RĂSPUNSUL: middleware-ul web
 * randează o pagină Inertia pe `Blocked`, redirectează Owner-ul spre
 * `settings.billing.index` și întoarce `back()->with('error', …)` pe o cerere Inertia.
 * Niciuna dintre cele trei forme nu are sens pentru un client de API, care primește HTML
 * în locul JSON-ului promis de contract. Deci: aceeași decizie, alt canal de livrare.
 *
 * ## De ce API-ul nu putea fi lăsat descoperit
 *
 * §12.2 cere ca la `unpaid` creare/editare/ștergere să fie blocate „indiferent de rol,
 * inclusiv Owner". Un API care continuă să accepte `POST /orders` în aceeași stare ar fi
 * fost o ocolire completă a degradării — și una tăcută, fiindcă interfața ar fi arătat
 * corect blocată. Golul nu e numit în §18 (specificația API-ului nu pomenește abonamentul)
 * și e semnalat în raportul lotului.
 *
 * Citirile rămân permise la `unpaid`, ca pe web — „vizualizare + export permise".
 */
class EnsureApiSubscriptionAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound('tenant')) {
            return $next($request);
        }

        $level = SubscriptionAccessPolicy::levelFor(app('tenant'));

        if ($level === SubscriptionAccessLevel::Full) {
            return $next($request);
        }

        if ($level === SubscriptionAccessLevel::Blocked) {
            return $this->refuse('This workspace subscription has been cancelled. The API is unavailable until it is reactivated from Settings → Billing.');
        }

        if ($request->isMethodSafe()) {
            return $next($request);
        }

        return $this->refuse('This workspace subscription is unpaid — the API is read-only until the payment method is updated from Settings → Billing.');
    }

    private function refuse(string $message): Response
    {
        return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
    }
}
