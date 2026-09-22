import { expect, test, type Page } from '@playwright/test';
import { authFile } from '../support/auth';
import { inertiaPageProps } from '../support/inertia';
import { deliverStripeWebhook, expectWebhookProcessed, linkWorkspaceToStripeCustomer, resetWorkspaceBilling, stripeEvent } from '../support/stripe';

/**
 * §24.3 pct. 8 (adăugat în specs.md v1.3, §12.2, FR-BILL-04/05, BR-BILL-03/04/05,
 * US-BILL-04) — degradarea pe 3 trepte, capăt la capăt:
 *
 *   `active` → `past_due` (banner, ACCES COMPLET) → „epuizarea reîncercărilor" → `unpaid`
 *   (read-only, verificat pe o acțiune de SCRIERE refuzată) → `invoice.payment_failed`
 *   retrimis de două ori → emailul de notificare pleacă O SINGURĂ DATĂ.
 *
 * Plus revenirea la `active`, care NU e în litera §24.3, dar e obligatorie aici din același
 * motiv pentru care fluxul rulează pe `northgate`: un `unpaid` scăpat în bază ar face
 * read-only un workspace întreg pentru testele care urmează. Northgate e singurul tenant
 * din seed pe care niciun alt spec nu-l atinge, iar `afterAll` curăță oricum abonamentul.
 *
 * „Simularea epuizării reîncercărilor" din textul cerinței e exact ce face Stripe: trimite
 * `customer.subscription.updated` cu `status: unpaid`. Nu există alt mecanism de imitat —
 * numărul de reîncercări e o politică din contul Stripe, invizibilă aplicației (BR-BILL-03:
 * „cât timp Stripe încă reîncearcă", adică `past_due`, accesul rămâne complet).
 *
 * Toate webhook-urile sunt semnate LOCAL (`e2e/support/stripe.ts`) — §22.4/BR-DEMO-03,
 * niciun apel către Stripe. Are nevoie de workerul de coadă: efectul fiecărui eveniment
 * se aplică în `ProcessStripeWebhookJob`, iar emailul într-un al doilea job
 * (`SendPaymentFailedDunningEmail`, `ShouldQueue`).
 */
test.use({ storageState: authFile('owner') });

const BASE = '/northgate';
const WEBHOOKS_URL = `${BASE}/settings/webhooks`;
const DASHBOARD_URL = `${BASE}/dashboard`;
const SENT_EMAILS_URL = `${BASE}/settings/sent-emails`;
const ACCOUNTS_URL = `${BASE}/accounts`;

const WORKSPACE_NAME = 'Northgate Restaurant Supply';

const ts = Date.now();
const CUSTOMER_ID = `cus_e2e_dunning_${ts}`;
const SUBSCRIPTION_ID = `sub_e2e_dunning_${ts}`;
const PAYMENT_FAILED_EVENT_ID = `evt_e2e_payment_failed_${ts}`;

const PAST_DUE_BANNER = "Your last payment failed — we're retrying automatically.";
const UNPAID_BANNER = 'Subscription unpaid — update your payment method to restore full access.';
const READ_ONLY_REFUSAL = 'Your subscription is unpaid — update your payment method to restore full access.';

const DUNNING_SUBJECT = `Payment failed for your ${WORKSPACE_NAME} subscription (attempt 2)`;
const UNPAID_TRANSITION_SUBJECT = `Action needed: ${WORKSPACE_NAME} subscription is unpaid`;

interface SubscriptionProps {
    subscription: { status: string | null; accessLevel: string } | null;
}

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

/** Livrează un eveniment și așteaptă ca ecranul de operare să-l arate terminal. */
async function deliverAndSettle(page: Page, event: ReturnType<typeof stripeEvent>, expectedBody = 'OK'): Promise<void> {
    const delivery = await deliverStripeWebhook(page, event);
    expect(delivery.status, `livrarea ${event.id} (${event.type})`).toBe(200);
    expect(delivery.body).toBe(expectedBody);

    await expectWebhookProcessed(page, WEBHOOKS_URL, event.id);
}

/** Rândurile jurnalului „Sent emails" cu un subiect dat (§22.3, `Settings/SentEmails/Index.tsx`). */
function sentEmailRows(page: Page, subject: string) {
    return page.locator('table tbody tr').filter({ hasText: subject });
}

/**
 * Deschide jurnalul și așteaptă ca propul DEFERRED `sentEmails` (FR-PERF-01) să SOSEASCĂ —
 * tabelul vine printr-o a doua cerere Inertia, după randarea inițială. Fără această
 * așteptare, un `.count()` imediat după `goto()` întoarce mereu 0, indiferent câte emailuri
 * există: ecranul e încă `TableSkeleton`. Skeleton-ul se anunță singur
 * (`role="status" aria-label="Loading"`), deci dispariția lui e condiția exactă — nu un
 * `waitForTimeout` și nici „așteaptă un `<table>`", care n-ar exista niciodată pe un jurnal
 * gol.
 */
async function openSentEmails(page: Page): Promise<void> {
    await page.goto(SENT_EMAILS_URL);
    await page.getByRole('heading', { name: 'Sent emails', level: 1 }).waitFor();
    await page.getByRole('status', { name: 'Loading' }).waitFor({ state: 'detached' });
}

/**
 * O SCRIERE reală, prin formularul real — `Accounts/Create`. Întoarce `true` dacă contul
 * chiar s-a creat. Deliberat NU o cerere directă către rută: §24.3 cere „cel puțin o
 * acțiune de scriere blocată", iar ce contează pentru un utilizator e ce pățește
 * formularul, nu ce întoarce un `curl`.
 */
async function tryCreateAccount(page: Page, name: string): Promise<void> {
    await page.goto(`${ACCOUNTS_URL}/create`);
    await expect(page.getByRole('heading', { name: 'New account', level: 1 })).toBeVisible();

    // `getByLabel` e nesigur pe câmpurile obligatorii (textul etichetei include
    // asteriscul `aria-hidden`) — vezi `deals-pipeline.spec.ts`.
    await page.getByRole('textbox', { name: 'Account name', exact: true }).fill(name);
    await page.getByRole('button', { name: 'Create account' }).click();
}

test.beforeAll(() => {
    linkWorkspaceToStripeCustomer('northgate', CUSTOMER_ID);
});

test.afterAll(() => {
    resetWorkspaceBilling('northgate');
});

test('dunning complet: active → past_due → unpaid (scriere blocată) → payment_failed retrimis → un singur email', { tag: ['@smoke'] }, async ({ page }) => {
    test.setTimeout(180_000);

    // ------------------------------------------------------------------ 1. `active`
    await deliverAndSettle(page, subscriptionUpdated('active', `evt_e2e_active_${ts}`));

    await page.goto(DASHBOARD_URL);
    await expect(page.getByText(PAST_DUE_BANNER)).toHaveCount(0);
    await expect(page.getByText(UNPAID_BANNER)).toHaveCount(0);

    // ------------------------------------------------- 2. `past_due` — banner, acces complet
    await deliverAndSettle(page, subscriptionUpdated('past_due', `evt_e2e_past_due_${ts}`));

    await page.goto(DASHBOARD_URL);
    await expect(page.getByText(PAST_DUE_BANNER)).toBeVisible();
    await expect(page.getByRole('link', { name: 'Update payment method' }).first()).toBeVisible();

    const shared = await inertiaPageProps<SubscriptionProps>(page, DASHBOARD_URL);
    expect(shared.subscription?.status).toBe('past_due');
    expect(shared.subscription?.accessLevel, 'BR-BILL-03 — Stripe încă reîncearcă, deci acces neschimbat').toBe('full');

    // Acces COMPLET înseamnă că o scriere chiar trece, nu doar că bannerul e galben.
    const allowedAccountName = `E2E Dunning Past Due ${ts}`;
    await tryCreateAccount(page, allowedAccountName);
    await expect(page).toHaveURL(/\/accounts\/(?!create)[^/?]+$/);
    await expect(page.getByRole('heading', { name: allowedAccountName, level: 1 })).toBeVisible();

    // --------------------------------------------- 3. `unpaid` — read-only, scriere refuzată
    await deliverAndSettle(page, subscriptionUpdated('unpaid', `evt_e2e_unpaid_${ts}`));

    await page.goto(DASHBOARD_URL);
    // Bannerul de `unpaid` e `role="alert"` (spre deosebire de cel de `past_due`) — filtrăm
    // pe text: `FlashMessages` ține o regiune `role="alert"` PERMANENTĂ în `<main>`, deci un
    // `getByRole('alert')` neascopat e ambiguu pe orice pagină a aplicației.
    await expect(page.getByRole('alert').filter({ hasText: UNPAID_BANNER })).toBeVisible();

    const readOnly = await inertiaPageProps<SubscriptionProps>(page, DASHBOARD_URL);
    expect(readOnly.subscription?.status).toBe('unpaid');
    expect(readOnly.subscription?.accessLevel).toBe('read_only');

    // Citirea rămâne posibilă (US-BILL-04): lista de conturi se deschide, cu contul creat
    // mai sus vizibil în ea.
    await page.goto(`${ACCOUNTS_URL}?filter[q]=${encodeURIComponent(allowedAccountName)}`);
    await expect(page.getByRole('link', { name: allowedAccountName, exact: true })).toBeVisible();

    // Scrierea NU. Refuzul vine din `EnsureSubscriptionAccess`, ca `flash.error` pe ecranul
    // de unde a plecat cererea — nu un 403 brut într-un modal.
    const refusedAccountName = `E2E Dunning Unpaid ${ts}`;
    await tryCreateAccount(page, refusedAccountName);

    await expect(page.getByRole('alert').filter({ hasText: READ_ONLY_REFUSAL })).toBeVisible();
    await expect(page, 'formularul rămâne pe loc — cererea n-a creat nimic').toHaveURL(/\/accounts\/create$/);

    await page.goto(`${ACCOUNTS_URL}?filter[q]=${encodeURIComponent(refusedAccountName)}`);
    await expect(page.getByText('No accounts match this filter.')).toBeVisible();

    // Tranziția `past_due → unpaid` trimite exact un email „de tranziție" (§12.2, tabelul de
    // degradare) — distinct de cel de dunning de mai jos.
    await expect
        .poll(
            async () => {
                await openSentEmails(page);

                return sentEmailRows(page, UNPAID_TRANSITION_SUBJECT).count();
            },
            { timeout: 30_000, intervals: [500, 1000, 2000] },
        )
        .toBe(1);

    // Referință pentru aserțiunea de idempotență de mai jos (pasul 4) — capturată AICI, nu
    // hardcodată „1": un alt agent schimbă chiar acum server-side trimiterea către „câte un
    // email PER Owner" (azi seed-ul demo are un singur Owner pe Northgate, deci numărul rămâne
    // 1, dar aserțiunea nu trebuie să presupună asta).
    const transitionEmailBaselineCount = await sentEmailRows(page, UNPAID_TRANSITION_SUBJECT).count();

    // ------------------------------- 4. `invoice.payment_failed` retrimis de DOUĂ ori
    const paymentFailed = stripeEvent(
        'invoice.payment_failed',
        { id: `in_e2e_dunning_${ts}`, customer: CUSTOMER_ID, attempt_count: 2 },
        PAYMENT_FAILED_EVENT_ID,
    );

    await deliverAndSettle(page, paymentFailed);

    await expect
        .poll(
            async () => {
                await openSentEmails(page);

                return sentEmailRows(page, DUNNING_SUBJECT).count();
            },
            { timeout: 30_000, intervals: [500, 1000, 2000] },
        )
        .toBe(1);

    // Referință pentru aserțiunea de idempotență pe `event_id` de mai jos — la fel ca la
    // tranziție, capturată dinamic, nu hardcodată.
    const dunningEmailBaselineCount = await sentEmailRows(page, DUNNING_SUBJECT).count();

    // A doua livrare a ACELUIAȘI eveniment (Stripe reîncearcă la timeout de rețea).
    const redelivery = await deliverStripeWebhook(page, paymentFailed);
    expect(redelivery.status).toBe(200);
    expect(redelivery.body).toBe('Event already recorded.');

    // BARIERĂ de coadă, în locul unui `waitForTimeout`: un eveniment NOU, livrat DUPĂ
    // retrimitere. Workerul e unul singur și consumă FIFO, deci în clipa în care acesta
    // apare `Processed`, orice job pe care retrimiterea l-ar fi dispecerizat ar fi rulat
    // deja. Statusul trimis e tot `unpaid` — identic cu cel curent, deci nu produce nicio
    // tranziție și niciun email nou (`ProcessStripeWebhookJob::syncSubscription()` compară
    // cu statusul ANTERIOR).
    await deliverAndSettle(page, subscriptionUpdated('unpaid', `evt_e2e_queue_barrier_${ts}`));

    await openSentEmails(page);
    // Idempotență pe `event_id` (NU pe „numărul de destinatari" — un fanout viitor „un email
    // per Owner" ar schimba baseline-ul de mai sus de la 1 la N, dar aserțiunea de-aici tot ar
    // trebui să treacă: a doua livrare a ACELUIAȘI `event_id` (`PAYMENT_FAILED_EVENT_ID`,
    // redistribuit mai sus) nu adaugă NIMIC față de numărul deja stabilit după prima livrare).
    await expect(
        sentEmailRows(page, DUNNING_SUBJECT),
        'idempotență pe event_id: a doua livrare a aceluiași invoice.payment_failed nu adaugă niciun email față de numărul de dinaintea redistribuirii',
    ).toHaveCount(dunningEmailBaselineCount);
    // Simetric — un al doilea `customer.subscription.updated` cu STATUSUL NESCHIMBAT
    // (`unpaid` → `unpaid`) nu e o tranziție nouă (`ProcessStripeWebhookJob::syncSubscription()`
    // compară cu statusul anterior), deci nu retrimite emailul de tranziție.
    await expect(
        sentEmailRows(page, UNPAID_TRANSITION_SUBJECT),
        'un status neschimbat (unpaid → unpaid) nu retrimite emailul de tranziție — numărul rămâne cel de dinaintea celui de-al doilea eveniment',
    ).toHaveCount(transitionEmailBaselineCount);

    // Emailul e interceptat (DEMO_MODE, BR-DEMO-02) și atribuit workspace-ului — altfel
    // n-ar fi vizibil AICI, în Settings-ul tenantului care tocmai a avut plata eșuată.
    await expect(sentEmailRows(page, DUNNING_SUBJECT)).toContainText('Intercepted');

    // ------------------------------------------- 5. Revenire la `active` — acces restaurat
    await deliverAndSettle(page, subscriptionUpdated('active', `evt_e2e_recovered_${ts}`));

    await page.goto(DASHBOARD_URL);
    await expect(page.getByText(UNPAID_BANNER)).toHaveCount(0);
    await expect(page.getByText(PAST_DUE_BANNER)).toHaveCount(0);

    const recoveredAccountName = `E2E Dunning Recovered ${ts}`;
    await tryCreateAccount(page, recoveredAccountName);
    await expect(page).toHaveURL(/\/accounts\/(?!create)[^/?]+$/);
    await expect(page.getByRole('heading', { name: recoveredAccountName, level: 1 })).toBeVisible();
});
