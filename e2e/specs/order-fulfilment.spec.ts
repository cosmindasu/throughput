import { expect, test } from '@playwright/test';
import { lookupVariants } from '../support/api';
import { authFile } from '../support/auth';
import { inertiaPageProps } from '../support/inertia';

/**
 * §24.3 pct. 4, INTEGRAL — „Comandă completă: creare → confirmare → shipment parțial →
 * shipment final → generare factură → înregistrare plată".
 *
 * Ultimii doi pași (factură + plată) sunt adăugați de valul 3 al Fazei 5, pe ACELAȘI test,
 * nu într-un fișier separat: cerința descrie UN flux continuu, iar factura se poate crea
 * doar de pe comanda ajunsă `confirmed`/`fulfilled` (`INVOICEABLE_ORDER_STATUSES` din
 * `BillingSection.tsx`, litera Gherkin a §12.1). Un al doilea fișier ar avea nevoie de
 * propriul fixture de comandă onorată — adică ar repeta exact ce face acesta.
 *
 * Faza 3, valul 2 (specs.md §11.2 pași 4-6, US-ORD-02/03, plan §9 Livrabile — „O comandă
 * parcurge draft → confirmed → partially_fulfilled → fulfilled") — onorarea prin
 * `DemoShippingCarrier` (ADR-010), FĂRĂ apel extern: Marlin (tenantul folosit de toată
 * suita) are `tenant_carrier_settings.provider = 'demo'` (`CarrierSettingsSeeder`), nu
 * `shippo` — verificat direct în seeder, nu presupus.
 *
 * Are nevoie de un worker de coadă (`e2e/playwright.config.ts`, al doilea `webServer`):
 * `GenerateShippingLabelJob` e dispecerizat DUPĂ commit (ADR-013).
 *
 * **Defect real găsit aici, reparat în `ShipmentsSection.tsx`** — `usePoll(3000,
 * {only:['order']}, {autoStart: hasPendingLabel})` (`@inertiajs/react`) pornește polling-ul
 * într-un `useEffect` cu array de dependențe GOL: `autoStart` se evaluează o singură
 * dată, la montarea componentei — ÎNAINTE să existe vreun shipment, deci mereu `false`.
 * Codul oprea polling-ul când `hasPendingLabel` devenea `false` (`stop()`), dar nu-l
 * pornea la loc când devenea `true` (lipsea `start()` simetric): eticheta se cumpăra
 * corect pe server, dar clientul nu mai cerea niciodată pagina din nou — „Generating
 * label…" rămânea pe ecran la nesfârșit. Fix: `start()`/`stop()` simetric pe
 * `hasPendingLabel`, într-un singur `useEffect`.
 *
 * Testul de mai jos e chiar dovada fixului: așteaptă eticheta DOAR prin polling-ul
 * Inertia real al paginii (fără `page.reload()`, fără `waitForTimeout`) — ar pica pe
 * timeout dacă `autoStart`/`start()` s-ar regresa la vechiul comportament.
 */
test.use({ storageState: authFile('manager') });

const BASE = '/marlin';
const ACCOUNTS_URL = `${BASE}/accounts`;

interface InvoiceShowProps {
    invoice: { id: string; status: string; total: number; balanceDue: number; invoiceNumber: string | null };
}

test('o comandă parcurge draft → confirmed → partially_fulfilled → fulfilled → factură → plată', async ({ page }) => {
    test.setTimeout(90_000);

    // O variantă cu destul stoc pentru DOUĂ shipment-uri parțiale (2 + 2), citită din
    // API-ul real de căutare (`VariantLookupController`), nu presupusă dintr-un SKU fix —
    // seed-ul e la scară redusă și parțial aleatoriu (§project.md, StockAndOrdersSeeder).
    const variants = await lookupVariants(page, BASE);
    const variant = variants.find((candidate) => candidate.available >= 4);
    expect(variant, 'are nevoie de o variantă cu disponibil ≥ 4 în seed').toBeTruthy();

    await page.goto(ACCOUNTS_URL);
    await page.getByRole('table').waitFor();
    const accountHref = await page.locator('table tbody tr').first().getByRole('link').first().getAttribute('href');
    expect(accountHref).toBeTruthy();

    // Fără link „New order" pe `Accounts/Show` încă (constatare separată, vezi raportul
    // agentului) — `Orders/Create` acceptă oricum `?account=` direct pe URL, exact ca
    // linkul „New deal" ar face-o.
    await page.goto(`${BASE}/orders/create?account=${accountHref!.split('/').pop()}`);
    await expect(page.getByRole('heading', { name: 'New order' })).toBeVisible();

    const variantSearch = page.getByRole('combobox', { name: 'Add a line' });
    await variantSearch.fill(variant!.sku);

    const option = page.getByRole('option', { name: new RegExp(escapeRegExp(variant!.sku)) });
    await expect(option).toBeVisible();
    await option.click();

    // Eticheta rândului nou adăugat (`OrderLinesEditor.newLineFromVariant`): „{name} · {sku}".
    const lineLabel = `${variant!.name} · ${variant!.sku}`;
    const quantityInput = page.getByLabel(`Quantity for ${lineLabel}`);
    await quantityInput.fill('4');

    await page.getByRole('button', { name: 'Create order' }).click();
    await expect(page).toHaveURL(/\/orders\/(?!create)[^/?]+$/);
    const orderUrl = page.url();

    // Confirmă — cantitatea (4) e sub disponibilul citit mai sus, deci fără promptul de
    // backorder (BR-STOCK-04): primul clic din dialog e suficient.
    await page.getByRole('button', { name: 'Confirm order' }).click();
    const confirmDialog = page.getByRole('dialog', { name: 'Confirm this order?' });
    await expect(confirmDialog).toBeVisible();
    await confirmDialog.getByRole('button', { name: 'Confirm order' }).click();
    await expect(confirmDialog).toBeHidden();

    // Starea comenzii e randată de DOUĂ ori pe `Orders/Show` — chip-ul din antet
    // (`StatusBadge`, lângă `<h1>`) ȘI pasul „Confirmed" din „Order timeline" — scopăm
    // strict pe chip (`rounded-full`, clasa distinctivă a `StatusBadge.tsx`, unică în
    // `<p>`-ul de descriere — celălalt `<span>` e wrapper-ul de layout al lui `Link`+chip).
    const orderStatusBadge = page.locator('h1').locator('xpath=following-sibling::p[1]').locator('span.rounded-full');
    await expect(orderStatusBadge).toHaveText('Confirmed');

    // Descrierea instantaneu a liniei (`BuildsOrderLines`): „{nume produs} — {sku}" — cu
    // liniuță lungă, DIFERITĂ de separatorul din combobox-ul de mai sus (·).
    const lineDescription = `${variant!.name} — ${variant!.sku}`;
    // Combinator de copil direct (`> ul > li`), NU orice descendent: fiecare shipment are
    // el însuși un `<ul><li>` intern, pentru liniile lui (`shipment.lines.map(...)`) — un
    // `li` neascopat ar prinde și acele rânduri imbricate, dublând numărul „real" de
    // shipment-uri la orice verificare de `count()`.
    const shipments = page.locator('section[aria-label="Shipments"] > ul > li');

    async function createAndShipPartial(quantity: number): Promise<void> {
        const shipToInput = page.getByLabel(`Quantity to ship for ${lineDescription}`);
        await shipToInput.fill(String(quantity));
        await page.getByRole('button', { name: 'Create shipment' }).click();

        // `shipments` sunt randate cele mai noi primele (`OrderController::show()`,
        // `latest('created_at')`) — shipment-ul TOCMAI creat e mereu primul `<li>`.
        //
        // Fără verificare explicită pe „Generating label…" — job-ul poate termina înainte
        // ca reload-ul de după `POST .../shipments` să apuce s-o arate (worker rapid),
        // deci starea tranzitorie nu e garantat vizibilă. Dovada fixului e „Label ready"
        // apărând FĂRĂ nicio acțiune a testului între timp — doar `usePoll`-ul real al
        // paginii (`ShipmentsSection.tsx`, la 3s) poate produce actualizarea. Timeout
        // generos: prima cerere a testului, workerul de coadă poate încă boot-a Laravel
        // (fără `url`/`wait` pe al doilea `webServer`, `e2e/playwright.config.ts`).
        const shipment = shipments.first();
        await expect(shipment.getByText('Label ready')).toBeVisible({ timeout: 30_000 });

        await shipment.getByRole('button', { name: 'Mark as shipped' }).click();
        const shipDialog = page.getByRole('dialog', { name: 'Mark this shipment as shipped?' });
        await expect(shipDialog).toBeVisible();
        await shipDialog.getByRole('button', { name: 'Mark as shipped' }).click();
        await expect(shipDialog).toBeHidden();
    }

    await createAndShipPartial(2);

    await expect(orderStatusBadge).toHaveText('Partially fulfilled');
    await expect(page.locator('section[aria-label="Lines"]').getByText('Shipped 2 of 4')).toBeVisible();

    await createAndShipPartial(2);

    await expect(orderStatusBadge).toHaveText('Fulfilled');
    await expect(page.locator('section[aria-label="Lines"]').getByText('Shipped 4 of 4')).toBeVisible();

    // Ambele shipment-uri există, ambele „In transit" (§11.3: `MarkShipmentShippedAction`
    // trece shipment-ul, nu comanda, direct la `in_transit` — livrarea rămâne Faza 2/5).
    await expect(page).toHaveURL(orderUrl);
    await expect(shipments).toHaveCount(2);
    await expect(shipments.nth(0).getByText('In transit')).toBeVisible();
    await expect(shipments.nth(1).getByText('In transit')).toBeVisible();

    // ---------------------------------------------------------------------------------
    // §24.3 pct. 4, pasul 5 — GENERARE FACTURĂ (Faza 5, US-BILL-01, §12.1).
    //
    // `BillingSection` își cere singură starea (`GET .../invoice-summary`, fetch propriu,
    // nu props Inertia — vezi docblock-ul componentei), deci secțiunea apare abia după ce
    // serverul confirmă `can.create`. `toBeVisible()` cu auto-retry acoperă exact asta;
    // niciun `waitForTimeout`.
    const billing = page.locator('section[aria-label="Billing"]');
    const createInvoice = billing.getByRole('button', { name: 'Create Invoice' });
    await expect(createInvoice).toBeVisible();
    await createInvoice.click();

    await expect(page).toHaveURL(/\/invoices\/[^/?]+$/);
    const invoiceUrl = page.url();

    // Același tipar de scopare ca pentru starea comenzii, mai sus — `PageHeader` randează
    // chip-ul de status în `<p>`-ul de descriere de sub `<h1>`; pe `Invoices/Show` acel
    // `<p>` mai conține și linkul către comandă.
    const invoiceStatusBadge = page.locator('h1').locator('xpath=following-sibling::p[1]').locator('span.rounded-full');
    await expect(invoiceStatusBadge).toHaveText('Draft');

    const paymentsSection = page.locator('section[aria-label="Payments"]');
    await expect(paymentsSection.getByText('No payments recorded yet.')).toBeVisible();

    // O factură `draft` NU acceptă încasări (`RegisterPaymentAction`: doar `sent`/`overdue`)
    // — formularul nici măcar nu e randat. Verificat explicit, nu presupus: e precondiția
    // pasului următor.
    await expect(paymentsSection.getByRole('button', { name: 'Record payment' })).toHaveCount(0);

    await page.getByRole('button', { name: 'Mark as sent' }).click();
    const sendDialog = page.getByRole('dialog', { name: 'Mark this invoice as sent?' });
    await expect(sendDialog).toBeVisible();
    await sendDialog.getByRole('button', { name: 'Mark as sent' }).click();
    await expect(sendDialog).toBeHidden();
    await expect(invoiceStatusBadge).toHaveText('Sent');

    // ---------------------------------------------------------------------------------
    // §24.3 pct. 4, pasul 6 — ÎNREGISTRARE PLATĂ (US-BILL-02).
    //
    // Suma se citește din props, nu din textul formatat de pe ecran: `formatMoney()`
    // scrie simbol de monedă și separatori de mii, iar un test care le-ar parseze ar
    // depinde de locale, nu de valoarea reală. `balanceDue` e un număr în props.
    const beforePayment = await inertiaPageProps<InvoiceShowProps>(page, invoiceUrl);
    expect(beforePayment.invoice.status).toBe('sent');
    expect(beforePayment.invoice.balanceDue, 'factura tocmai emisă are de încasat exact totalul').toBe(beforePayment.invoice.total);
    expect(beforePayment.invoice.balanceDue).toBeGreaterThan(0);

    await paymentsSection.getByRole('spinbutton', { name: 'Amount' }).fill(beforePayment.invoice.balanceDue.toFixed(2));
    await paymentsSection.getByRole('button', { name: 'Record payment' }).click();

    // Încasare COMPLETĂ → `balance_due` ajunge 0, deci statusul trece singur pe `paid`
    // (US-BILL-02, „a doua plată... status devine paid automat" —
    // `RegisterPaymentAction`).
    await expect(invoiceStatusBadge).toHaveText('Paid');

    const recordedPayments = paymentsSection.getByRole('listitem');
    await expect(recordedPayments).toHaveCount(1);
    await expect(recordedPayments.first()).toContainText('Bank transfer');
    await expect(recordedPayments.first()).toContainText('Marcus Reyes');

    const afterPayment = await inertiaPageProps<InvoiceShowProps>(page, invoiceUrl);
    expect(afterPayment.invoice.status).toBe('paid');
    expect(afterPayment.invoice.balanceDue).toBe(0);

    // Bucla se închide pe comandă: secțiunea de facturare nu mai oferă „Create Invoice",
    // ci linkul către factura existentă, cu starea ei (BR-ORD-05 — o comandă, o factură).
    await page.goto(orderUrl);
    await expect(billing.getByRole('button', { name: 'Create Invoice' })).toHaveCount(0);
    await expect(billing.getByRole('link', { name: afterPayment.invoice.invoiceNumber! })).toBeVisible();
    await expect(billing.getByText('paid', { exact: true })).toBeVisible();
});

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
