import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Settings/Billing/Index` — specs.md §12.2 (abonamentul Throughput, Billable = tenant,
 * ADR-006), §12.3 (webhook-uri idempotente), §7.4 (Owner-only — Managerul nu vede nici
 * măcar cardul din Settings).
 *
 * Reconciliat cu codul la 2026-09-20 (lotul de abonament, Faza 5):
 * `App\Http\Controllers\Web\Settings\BillingController`, `SubscriptionBanner.tsx`,
 * `App\Http\Middleware\EnsureSubscriptionAccess`, `App\Support\Billing\SubscriptionAccessPolicy`.
 */
const billing: HelpTopic = {
    id: 'billing',
    title: 'Billing & Subscription',
    whatIsThis:
        "Where this workspace's own Throughput subscription lives — the plan, the payment method on file, and what happens when a payment fails. This is not the invoices you send to your customers; that is a completely separate ledger, on a different page.",
    whatCanYouDo: [
        'See the current subscription status and, if one is on file, the card used to pay for it.',
        'Press "Manage billing" to open the Stripe Customer Portal in a new tab, where the payment method and billing history can be updated directly with Stripe.',
        'If the subscription was canceled, press "Reactivate" from the same portal, any time within 30 days of cancellation — no need to set the workspace up again.',
    ],
    rules: [
        'Only Owner can open this page or manage billing at all — not Manager, even though Manager has full operational access everywhere else. The card for this page does not even appear in Settings for anyone but Owner.',
        'A failed payment does not lock anything by itself. Stripe retries automatically, and while that is happening ("past due") every role keeps full access — a banner just says so, on every page, with a link back here.',
        'Only once Stripe has exhausted its retries and marked the subscription "unpaid" does write access stop, for every role including Owner: viewing and exporting data still work, but creating, editing or deleting anything anywhere in the workspace is refused, both in the interface and on the server if the request is somehow forced through.',
        'A canceled subscription blocks the entire workspace except this one page: every other screen redirects here automatically, because reactivating is the only thing left to do — and data stays exportable for 30 days from cancellation before it would be scheduled for removal.',
        'The Owner gets an email every time Stripe reports a failed payment attempt while retries are still happening, with the attempt number, so the problem is visible long before the workspace actually loses access.',
    ],
    howItsBuilt: {
        summary:
            'The subscription lives on the workspace itself, not on any individual user — Cashier treats the tenant as the billable entity, so the plan survives whoever originally entered the card. A single policy reads the raw Stripe status directly (not the higher-level "subscribed" helper, which cannot tell "still retrying" apart from "given up") and turns it into one of three access levels, checked by one piece of middleware in front of every write route in the workspace and read again by the banner shown on every page, so the server and the interface can never disagree about which state the workspace is in. Stripe delivers webhook events at least once, never exactly once, so every event is recorded before it is acted on, keyed on its own id — a repeat delivery is recognized immediately and never applied twice, whether it is the ordinary subscription-status sync or the payment-failure notice, which is not something Stripe\'s own library handles on its own and had to be added deliberately.',
        adr: {
            id: 'ADR-006',
            title: 'Laravel Cashier 16 for the tenant subscription',
            url: adrUrl('ADR-006', 'cashier-16-pentru-abonament'),
        },
    },
};

export default billing;
