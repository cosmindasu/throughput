import type { APIResponse, Page } from '@playwright/test';

/**
 * Antetul CSRF pentru cereri `page.request.*` directe către rute care nu trec prin
 * clientul Inertia (`router.post`/`useForm`) — același tipar ca `e2e/support/theme.ts`
 * (`setUserTheme`): Laravel scrie `XSRF-TOKEN` CRIPTAT pe orice răspuns din grupul `web`,
 * `VerifyCsrfToken` acceptă aceeași valoare înapoi pe `X-XSRF-TOKEN`. `page.request`
 * împarte contextul (și cookie-urile) cu `page`, deci sesiunea autentificată prin
 * `storageState` e deja acolo — nu trebuie reautentificat nimic.
 */
export async function xsrfHeader(page: Page): Promise<Record<string, string>> {
    const cookies = await page.context().cookies();
    const token = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value;

    return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {};
}

/**
 * Verifică un răspuns non-2xx/3xx și aruncă cu context util (URL, status, corp) — fără
 * asta, un eșec de fixture (cont fără drept, validare picată) apare ca o eroare opacă mult
 * mai jos în test, la asertarea care se aștepta la efectul lui.
 */
export async function expectOk(response: APIResponse, context: string): Promise<APIResponse> {
    if (response.ok() || response.status() < 400) {
        return response;
    }

    const body = await response.text().catch(() => '<corp ilizibil>');
    throw new Error(`${context}: ${response.status()} ${response.statusText()} — ${body.slice(0, 500)}`);
}

/**
 * Creează comenzi `draft` goale (fără linii — US-ORD-01, un draft se poate salva gol),
 * direct prin `StoreOrderRequest` (`account_id` e singurul câmp obligatoriu), ca fixture
 * rapid pentru scenariile de operații în masă care au nevoie de volum peste pragul de
 * confirmare (specs.md §13.1, FR-BULK-01) — construirea a >100 draft-uri prin formularul
 * `Orders/Create` ar costa minute, nu secunde, pentru un fixture care nu testează el
 * însuși formularul de creare (deja acoperit de `order-fulfilment.spec.ts`).
 *
 * Loturi de `concurrency` cereri simultane, nu toate deodată: `php artisan serve` (webServer-ul
 * suitei) nu garantează paralelism real, dar loturile mărginesc oricum memoria/soclurile
 * deschise simultan și dau un eșec izolat (un lot, nu tot loop-ul) dacă ceva pică la mijloc.
 */
export async function createDraftOrders(page: Page, base: string, accountId: string, count: number, concurrency = 20): Promise<void> {
    const headers = await xsrfHeader(page);
    let created = 0;

    while (created < count) {
        const batchSize = Math.min(concurrency, count - created);

        // Loturi INTENȚIONAT secvențiale (vezi docblock-ul) — `await` în buclă, nu o
        // singură rafală de `count` cereri simultane.
        await Promise.all(
            Array.from({ length: batchSize }, async () => {
                const response = await page.request.post(`${base}/orders`, {
                    headers,
                    data: { account_id: accountId },
                    maxRedirects: 0,
                    failOnStatusCode: false,
                });

                await expectOk(response, `Creare draft order (fixture) la ${base}/orders`);
            }),
        );

        created += batchSize;
    }
}

/** Contul din primul rând al listei curente (respectă filtrul implicit al rolului — „My accounts" pentru Agent) — fixture pentru orice test care are nevoie de un `account_id` valid, fără să inventeze unul. */
export async function firstAccountId(page: Page, accountsUrl: string): Promise<string> {
    await page.goto(accountsUrl);
    await page.getByRole('table').waitFor();

    const href = await page.locator('table tbody tr').first().getByRole('link').first().getAttribute('href');
    if (!href) {
        throw new Error(`Niciun cont găsit la ${accountsUrl} — fixture-ul are nevoie de cel puțin un rând.`);
    }

    const id = href.split('/').filter(Boolean).pop();
    if (!id) {
        throw new Error(`Nu s-a putut citi id-ul contului din href-ul „${href}".`);
    }

    return id;
}

export interface VariantLookupOption {
    id: string;
    sku: string;
    name: string;
    price: number;
    available: number;
}

/**
 * `VariantLookupController` — variantele active ale tenantului, cu `available` calculat la
 * locația implicită (specs.md §10.5), EXACT ce citește `VariantCombobox.tsx`. Fără `q`,
 * întoarce primele (până la 20) în ordine de SKU — suficient ca fixture, fără să inventăm
 * un SKU care s-ar putea să nu existe la scara redusă a suitei.
 */
export async function lookupVariants(page: Page, base: string): Promise<VariantLookupOption[]> {
    const response = await page.request.get(`${base}/orders/variants/lookup`, { headers: { Accept: 'application/json' } });
    await expectOk(response, `Căutare variante la ${base}/orders/variants/lookup`);

    const body = (await response.json()) as { data: VariantLookupOption[] };

    return body.data;
}

/**
 * Creează o comandă cu O linie (`CreateOrderAction`, §11.1) și întoarce id-ul ei, citit din
 * antetul `Location` al redirectului (`OrderController::store()` → `orders.show`) — fără să
 * urmeze redirectul (`maxRedirects: 0`), ca fixture-ul să nu coste o cerere GET în plus.
 */
export async function createOrderWithLine(
    page: Page,
    base: string,
    params: { accountId: string; variantId: string; quantity: number },
): Promise<string> {
    const headers = await xsrfHeader(page);
    const response = await page.request.post(`${base}/orders`, {
        headers,
        data: {
            account_id: params.accountId,
            lines: [{ variant_id: params.variantId, quantity: params.quantity }],
        },
        maxRedirects: 0,
        failOnStatusCode: false,
    });
    await expectOk(response, `Creare comandă cu linie la ${base}/orders`);

    const location = response.headers()['location'];
    if (!location) {
        throw new Error(`Răspunsul de creare a comenzii n-a avut antet Location (status ${response.status()}).`);
    }

    const orderId = location.split('/').filter(Boolean).pop();
    if (!orderId) {
        throw new Error(`Nu s-a putut citi id-ul comenzii din antetul Location „${location}".`);
    }

    return orderId;
}

/** `ConfirmOrderController` — confirmă un draft (BR-ORD-02: `order_number` generat ACUM, la confirmare). */
export async function confirmOrder(page: Page, base: string, orderId: string, acknowledgeBackorder = false): Promise<void> {
    const headers = await xsrfHeader(page);
    const response = await page.request.patch(`${base}/orders/${orderId}/confirm`, {
        headers,
        data: { acknowledge_backorder: acknowledgeBackorder },
        maxRedirects: 0,
        failOnStatusCode: false,
    });
    await expectOk(response, `Confirmare comandă la ${base}/orders/${orderId}/confirm`);
}
