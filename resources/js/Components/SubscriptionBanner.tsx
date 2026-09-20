import { Link, usePage } from '@inertiajs/react';

/**
 * specs.md §12.2 — bannerul de degradare pe 3 trepte. Randat pe ORICE pagină (`AppLayout`),
 * din propul comun `subscription` (`App\Http\Middleware\HandleInertiaRequests::share()`),
 * nu recalculat din nimic altceva în React (§1.2 regula 2/3).
 *
 * - `active`/fără abonament încă → nimic (`status !== 'past_due'` mai jos).
 * - `past_due` → banner PERSISTENT, NON-BLOCANT (BR-BILL-03: acces neschimbat, Stripe încă
 *   reîncearcă) — tokens de warning, nu danger: nu e o eroare a aplicației.
 * - `unpaid` (`accessLevel === 'read_only'`) → banner BLOCANT vizual (tokens de danger),
 *   dar tot ne-modal: US-BILL-04 cere ca vizualizarea să rămână posibilă, doar scrierea e
 *   refuzată (server-side, `EnsureSubscriptionAccess`) — un banner care ar ascunde
 *   restul paginii ar contrazice exact asta.
 * - `canceled` (`accessLevel === 'blocked'`) — NU se randează aici: `EnsureSubscriptionAccess`
 *   redirectează server-side către `Settings/Billing/Index`, care își arată singur ecranul
 *   unic „Subscription canceled. [Reactivate]" (US-BILL-04, Gherkin).
 */
export default function SubscriptionBanner() {
    const { subscription, workspace } = usePage().props;

    if (!subscription || subscription.accessLevel === 'blocked') {
        return null;
    }

    // Fără Ziggy în proiect (vezi `AppLayout.tsx`, `NAV_ITEMS`) — calea se construiește cu
    // slug-ul workspace-ului curent, la fel ca restul linkurilor din interfață.
    const billingHref = workspace ? `/${workspace.slug}/settings/billing` : '#';

    if (subscription.status === 'past_due') {
        return (
            <div className="w-full border-b border-border bg-warning-tint text-warning">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-2 sm:px-6 lg:px-8">
                    <p className="text-sm">
                        Your last payment failed — we&apos;re retrying automatically.
                    </p>
                    <Link
                        href={billingHref}
                        className="shrink-0 rounded-md px-2 py-1 text-xs font-medium underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    >
                        Update payment method
                    </Link>
                </div>
            </div>
        );
    }

    if (subscription.accessLevel === 'read_only') {
        return (
            <div className="w-full border-b border-border bg-danger-tint text-danger" role="alert">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-2 sm:px-6 lg:px-8">
                    <p className="text-sm font-medium">
                        Subscription unpaid — update your payment method to restore full access.
                    </p>
                    <Link
                        href={billingHref}
                        className="shrink-0 rounded-md px-2 py-1 text-xs font-medium underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    >
                        Update payment method
                    </Link>
                </div>
            </div>
        );
    }

    return null;
}
