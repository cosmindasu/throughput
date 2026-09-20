import { createHmac } from 'node:crypto';
import { expect, type Page } from '@playwright/test';
import { tinker, TINKER_OK } from './artisan';
import { APP_URL, STRIPE_WEBHOOK_SECRET } from './env';

/**
 * Webhook-uri Stripe SEMNATE LOCAL, pentru fluxurile 7 și 8 din specs.md §24.3.
 *
 * §22.4 / BR-DEMO-03 — „Stripe rulează EXCLUSIV în test mode": aici nici măcar atât.
 * Niciun apel către Stripe, nicio cheie reală, niciun `stripe listen`: semnătura se
 * calculează cu HMAC-SHA256 peste `{timestamp}.{payload}`, exact formatul pe care
 * `Stripe\WebhookSignature::verifyHeader()` îl validează în controller, cu secretul
 * local din `e2e/support/env.ts` (`STRIPE_WEBHOOK_SECRET`, pasat serverului de
 * `e2eEnv()`). Echivalentul în suita Pest e `Tests\Concerns\SignsStripeWebhooks`, care
 * face același lucru prin SDK-ul PHP — același contract, două limbaje.
 */

const WEBHOOK_URL = `${APP_URL}/webhooks/stripe`;

export interface StripeEvent {
    id: string;
    type: string;
    data: { object: Record<string, unknown> };
}

export function stripeEvent(type: string, object: Record<string, unknown>, eventId: string): StripeEvent {
    return { id: eventId, type, data: { object } };
}

/**
 * Antetul `Stripe-Signature` pentru un payload dat. Timestamp-ul e „acum" — toleranța
 * implicită a lui Cashier e 300 s (`cashier.webhook.tolerance`), deci un eveniment
 * RETRIMIS mai târziu în același test tot trece: Stripe însuși resemnează la fiecare
 * livrare, nu reia antetul vechi. Ce se repetă la retrimitere e `event.id`, singurul
 * lucru pe care idempotența din §12.3 îl folosește.
 */
function signatureHeader(payload: string): string {
    const timestamp = Math.floor(Date.now() / 1000);
    const signature = createHmac('sha256', STRIPE_WEBHOOK_SECRET).update(`${timestamp}.${payload}`).digest('hex');

    return `t=${timestamp},v1=${signature}`;
}

export interface WebhookDelivery {
    status: number;
    body: string;
}

/**
 * Livrează evenimentul exact ca Stripe: `POST /webhooks/stripe`, corp RAW identic cu
 * octeții semnați (`data` primește ȘIRUL, nu obiectul — altfel Playwright ar
 * re-serializa, fără garanția că rezultatul e byte-identic cu ce s-a semnat; aceeași
 * precauție ca `postStripeWebhook()` din suita Pest).
 *
 * Ruta e publică și exceptată de la CSRF (`bootstrap/app.php`), deci sesiunea din
 * `storageState` nu joacă niciun rol aici — dar `page.request` rămâne contextul cel mai
 * la îndemână, iar cookie-urile lui nu schimbă nimic pentru un endpoint fără sesiune.
 */
export async function deliverStripeWebhook(page: Page, event: StripeEvent): Promise<WebhookDelivery> {
    const payload = JSON.stringify(event);

    const response = await page.request.post(WEBHOOK_URL, {
        headers: {
            'Content-Type': 'application/json',
            'Stripe-Signature': signatureHeader(payload),
        },
        data: payload,
        maxRedirects: 0,
        failOnStatusCode: false,
    });

    return { status: response.status(), body: (await response.text()).trim() };
}

/**
 * Leagă un workspace de un „client Stripe" inventat, ca `StripeWebhookController` să-l
 * găsească după `data.object.customer`. Vezi `e2e/support/artisan.ts` pentru de ce nu
 * există alt drum: coloana se scrie în producție doar printr-un apel real la Stripe.
 *
 * `tenants` nu are RLS (ADR-014 pct. 4, exact ca să permită acest lookup fără context),
 * deci fragmentul nu are nevoie de `TenantContext::run()`.
 */
export function linkWorkspaceToStripeCustomer(slug: string, customerId: string): void {
    tinker(
        `$t = \\App\\Models\\Tenant::query()->where('slug', '${slug}')->firstOrFail();` +
            `$t->forceFill(['stripe_id' => '${customerId}', 'subscription_canceled_at' => null])->save();` +
            `echo '${TINKER_OK}', PHP_EOL;`,
    );
}

/**
 * Readuce workspace-ul la starea „nicio facturare încă" — `subscriptions` gol +
 * `stripe_id` null, adică exact ce semănă `DemoDatasetSeeder` (care nu scrie niciodată
 * nici una, nici alta).
 *
 * Obligatoriu în `afterAll`: `SubscriptionAccessPolicy` citește `stripe_status` pe ORICE
 * cerere din workspace, deci un abonament `unpaid` uitat în bază ar face read-only un
 * workspace întreg pentru testele care urmează. Rulează chiar și dacă testul a picat la
 * mijloc, tocmai ca eșecul să rămână LOCAL.
 */
export function resetWorkspaceBilling(slug: string): void {
    tinker(
        `$t = \\App\\Models\\Tenant::query()->where('slug', '${slug}')->firstOrFail();` +
            `$ids = \\Illuminate\\Support\\Facades\\DB::table('subscriptions')->where('user_id', $t->getKey())->pluck('id');` +
            `\\Illuminate\\Support\\Facades\\DB::table('subscription_items')->whereIn('subscription_id', $ids)->delete();` +
            `\\Illuminate\\Support\\Facades\\DB::table('subscriptions')->whereIn('id', $ids)->delete();` +
            `$t->forceFill(['stripe_id' => null, 'subscription_canceled_at' => null])->save();` +
            `echo '${TINKER_OK}', PHP_EOL;`,
    );
}

/**
 * Ecranul „Webhook health" (`/{workspace}/settings/webhooks`, Owner-only) — rândul unui
 * eveniment, identificat prin `event_id`-ul afișat sub tipul evenimentului.
 *
 * ACESTA e criteriul de acceptanță al §12.3 („`error_message` populat, VIZIBIL într-un
 * ecran de operare") și singura observabilitate din interfață pentru fluxul 7: că a doua
 * livrare a aceluiași `event_id` nu a produs un al doilea rând se vede AICI, nu doar în
 * bază.
 */
export function webhookEventRow(page: Page, eventId: string) {
    return page.locator('table tbody tr').filter({ hasText: eventId });
}

/**
 * Așteaptă ca evenimentul să ajungă terminal pe ecranul de operare. Polling explicit
 * prin reîncărcarea paginii (ecranul NU face `usePoll` — e o listă de operare, nu o
 * pagină de progres), cu `expect.poll`, niciodată `waitForTimeout`.
 */
export async function expectWebhookProcessed(page: Page, webhooksUrl: string, eventId: string): Promise<void> {
    await expect
        .poll(
            async () => {
                await page.goto(webhooksUrl);

                return (await webhookEventRow(page, eventId).first().textContent()) ?? '';
            },
            { timeout: 30_000, intervals: [500, 1000, 1000, 2000] },
        )
        .toContain('Processed');
}
