import { expect, test } from '@playwright/test';
import { authFile } from '../support/auth';
import { inertiaPageProps } from '../support/inertia';
import {
    deliverStripeWebhook,
    expectWebhookProcessed,
    linkWorkspaceToStripeCustomer,
    resetWorkspaceBilling,
    stripeEvent,
    webhookEventRow,
} from '../support/stripe';

/**
 * §24.3 pct. 7 — „Webhook Stripe retrimis de două ori (simulare) → verificare că efectul
 * se aplică o singură dată". Riscul e numit explicit în specs.md §26 („Webhook Stripe
 * procesat de două ori din cauza livrării at-least-once"), cu acest test ca măsură.
 *
 * **Workspace: `northgate`, nu `marlin`.** Un webhook de abonament schimbă
 * `stripe_status`, iar `App\Support\Billing\SubscriptionAccessPolicy` îl citește pe
 * ORICE cerere din acel workspace — un `past_due` uitat pe Marlin ar pune bannerul de
 * facturare pe fiecare pagină a celorlalte 60+ de teste, iar un `unpaid` le-ar bloca
 * scrierile. Northgate e singurul tenant din seed pe care NICIUN alt spec nu-l atinge
 * (Manager/Agent/Viewer nici măcar nu-s membri acolo — `UsersAndMembershipsSeeder`),
 * deci raza de acțiune a acestui fișier se oprește la el. `afterAll` curăță oricum.
 *
 * **Observabilitatea e din interfață, nu din bază**: ecranul „Webhook health"
 * (`/{workspace}/settings/webhooks`, Owner-only) e chiar ecranul de operare cerut de
 * criteriul de acceptanță al §12.3. Unicitatea pe `(source, event_id)` la nivel de
 * schemă rămâne verificată de `tests/Feature/Webhooks/StripeWebhookIdempotencyTest.php`
 * (alt lot); aici se verifică ce VEDE și ce PĂȚEȘTE un operator.
 *
 * Are nevoie de workerul de coadă (`e2e/playwright.config.ts`, al doilea `webServer`):
 * controllerul doar înregistrează evenimentul și dispecerizează
 * `ProcessStripeWebhookJob` (ADR-013 — efectul nu se aplică în cererea HTTP).
 */
test.use({ storageState: authFile('owner') });

const BASE = '/northgate';
const WEBHOOKS_URL = `${BASE}/settings/webhooks`;
const BILLING_URL = `${BASE}/settings/billing`;

const ts = Date.now();
const CUSTOMER_ID = `cus_e2e_idem_${ts}`;
const SUBSCRIPTION_ID = `sub_e2e_idem_${ts}`;
const EVENT_ID = `evt_e2e_redelivered_${ts}`;

interface WebhookHealthProps {
    counts: Record<string, number>;
}

interface BillingProps {
    subscription: { status: string | null; accessLevel: string };
}

/**
 * Aceeași formă de `customer.subscription.updated` pe care o trimite Stripe — cu
 * `items.data[0].price`, fiindcă `ProcessStripeWebhookJob::syncSubscription()` scrie
 * `stripe_price`/`quantity` din el. Statusul e parametru: a treia livrare de mai jos
 * refolosește ACELAȘI `event_id` cu un status DIFERIT, ca proba de idempotență să poată
 * într-adevăr eșua (vezi comentariul de acolo).
 */
function subscriptionUpdated(status: string, eventId: string) {
    return stripeEvent(
        'customer.subscription.updated',
        {
            id: SUBSCRIPTION_ID,
            customer: CUSTOMER_ID,
            status,
            items: { data: [{ id: 'si_e2e_1', price: { id: 'price_e2e_pro', product: 'prod_e2e_pro' }, quantity: 1 }] },
        },
        eventId,
    );
}

test.beforeAll(() => {
    linkWorkspaceToStripeCustomer('northgate', CUSTOMER_ID);
});

test.afterAll(() => {
    resetWorkspaceBilling('northgate');
});

test('același event_id livrat de două ori: un singur rând de operare, un singur efect asupra abonamentului', { tag: ['@smoke'] }, async ({ page }) => {
    test.setTimeout(90_000);

    const before = await inertiaPageProps<WebhookHealthProps>(page, WEBHOOKS_URL);
    const processedBefore = before.counts.processed ?? 0;

    // ---- Livrarea 1 — evenimentul nou, procesat normal.
    const event = subscriptionUpdated('past_due', EVENT_ID);

    const first = await deliverStripeWebhook(page, event);
    expect(first.status, 'prima livrare trebuie acceptată').toBe(200);
    expect(first.body).toBe('OK');

    await expectWebhookProcessed(page, WEBHOOKS_URL, EVENT_ID);

    // Efectul s-a aplicat: ecranul de facturare al workspace-ului arată noul status.
    // `statusLabel()` din `Settings/Billing/Index.tsx` înlocuiește `_` cu spațiu.
    await page.goto(BILLING_URL);
    await expect(page.getByText('past due', { exact: true })).toBeVisible();

    // ---- Livrarea 2 — RETRIMITEREA: același `event_id`, același corp, semnătură nouă
    // (Stripe resemnează la fiecare încercare; ce se repetă e id-ul evenimentului).
    const second = await deliverStripeWebhook(page, event);
    expect(second.status, 'retrimiterea trebuie tot 200 — Stripe n-are ce reîncerca').toBe(200);
    expect(second.body, 'controllerul recunoaște explicit un eveniment deja înregistrat').toBe('Event already recorded.');

    // ---- Livrarea 3 — ACELAȘI `event_id`, dar cu `status: canceled`.
    //
    // Stripe NU face asta (retrimite corpul identic). E o sondă deliberată de
    // FALSIFICABILITATE: cu un corp identic, un test de idempotență trece și dacă
    // deduplicarea e complet ruptă, fiindcă a doua aplicare ar scrie exact aceleași
    // valori. Cu un corp diferit, o deduplicare ruptă ar lăsa urmă vizibilă — abonamentul
    // ar ajunge `canceled`, deci `accessLevel = blocked`, iar `EnsureSubscriptionAccess`
    // ar redirecta tot workspace-ul către ecranul de reactivare. Dacă testul de mai jos
    // pică, chiar există un defect.
    const third = await deliverStripeWebhook(page, subscriptionUpdated('canceled', EVENT_ID));
    expect(third.status).toBe(200);
    expect(third.body).toBe('Event already recorded.');

    // ---- Ecranul de operare: UN singur rând pentru acel `event_id`, și exact un
    // eveniment procesat în plus față de starea de dinainte — nu două, nu trei.
    await page.goto(WEBHOOKS_URL);
    await expect(webhookEventRow(page, EVENT_ID)).toHaveCount(1);
    await expect(webhookEventRow(page, EVENT_ID)).toContainText('Processed');

    const after = await inertiaPageProps<WebhookHealthProps>(page, WEBHOOKS_URL);
    expect(after.counts.processed ?? 0, 'trei livrări, un singur eveniment procesat').toBe(processedBefore + 1);

    // ---- Efectul rămâne cel al PRIMEI (și singurei) procesări.
    const billing = await inertiaPageProps<BillingProps>(page, BILLING_URL);
    expect(billing.subscription.status).toBe('past_due');
    expect(billing.subscription.accessLevel, 'BR-BILL-03 — `past_due` păstrează accesul complet').toBe('full');
    await expect(page.getByText('past due', { exact: true })).toBeVisible();
});

/**
 * Cealaltă jumătate a §12.3, pct. 1 — o semnătură invalidă nu ajunge niciodată pe ecranul
 * de operare, fiindcă nimic nu se persistă înainte de verificare. Ieftin (nicio coadă,
 * nicio pagină), dar e singura dovadă din interfață că ecranul de operare nu poate fi
 * populat de oricine cunoaște URL-ul public.
 */
test('un webhook cu semnătură invalidă e respins cu 400 și nu apare deloc pe ecranul de operare', { tag: ['@smoke'] }, async ({ page }) => {
    const forgedId = `evt_e2e_forged_${ts}`;

    const response = await page.request.post('/webhooks/stripe', {
        headers: { 'Content-Type': 'application/json', 'Stripe-Signature': 't=1700000000,v1=not-a-real-signature' },
        data: JSON.stringify(subscriptionUpdated('canceled', forgedId)),
        maxRedirects: 0,
        failOnStatusCode: false,
    });

    expect(response.status()).toBe(400);

    await page.goto(WEBHOOKS_URL);
    await expect(webhookEventRow(page, forgedId)).toHaveCount(0);
});
