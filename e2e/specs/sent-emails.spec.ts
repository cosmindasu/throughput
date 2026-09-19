import { expect, test } from '@playwright/test';
import { createReport, runReportNow } from '../support/api';
import { authFile, DEMO_ROLE_LABELS, type DemoRole } from '../support/auth';
import { inertiaPageProps } from '../support/inertia';

/**
 * Faza 4 (specs.md §22.3, BR-DEMO-02) — jurnalul „Sent Emails" (`Settings/SentEmails/Index.tsx`).
 *
 * **Constatare importantă, raportată aici și în raportul agentului, NU ascunsă cu un test
 * artificial**: verificarea explicit cerută — „un link de resetare a parolei apare redactat,
 * nu în clar, în jurnal" — nu se poate scrie ca test E2E prin acest ecran, din motive
 * ARHITECTURALE, deliberate și documentate (ADR-020, migrația `sent_emails`,
 * `App\Models\SentEmail` docblock): `PasswordResetLinkController` stă pe rute `guest`, în
 * afara oricărui context de tenant/workspace — emailul de resetare a parolei se scrie
 * NECONDIȚIONAT cu `tenant_id = NULL`. Politica RLS a tabelei (`sent_email_visibility`) are
 * o a doua ramură EXPLICITĂ pentru acest caz: un rând FĂRĂ tenant nu e vizibil sub NICIUN
 * context de tenant activ — nici pentru Owner, nici pentru Manager, pe NICIUN workspace. Deci
 * un test care ar cere `/forgot-password` și apoi ar deschide `{workspace}/settings/sent-emails`
 * n-ar găsi NICIODATĂ acel rând, indiferent dacă link-ul e redactat sau nu — nu e un defect,
 * e comportamentul documentat (opțiunea 3 din cele 3 cântărite în migrație). Redactarea
 * (`App\Support\Mail\SentEmailRedactor`) rămâne verificabilă doar la nivel de server
 * (Pest, alt lot) sau printr-un email care ARE tenant — dar niciun email cu tenant din
 * aplicație de azi conține vreun link de tip reset-password/token/signature (verificat în
 * sursă: singurul email cu tenant e `ReportDeliveryMail`, un fișier atașat, fără link).
 *
 * Ce testează fișierul ăsta în loc: jurnalul chiar înregistrează un email interceptat, cu
 * starea corectă (`Intercepted` — `DEMO_EMAIL_ALLOWLIST` gol în mediul E2E,
 * `e2e/support/env.ts`, deci ORICE destinatar e interceptat), plus verificarea explicită
 * cerută pentru golul de focus rămas din Faza 3 (SC 2.4.11) pe panoul de detaliu, care aici
 * e o extindere INLINE (`<tr>`, nu un overlay) — diferit de `ColumnSelector`, verificat deja
 * în `columns.spec.ts`.
 *
 * Fixture: un raport creat + rulat direct prin API (`createReport`/`runReportNow`,
 * `e2e/support/api.ts`) — fluxul UI complet de „Run now" e deja testat, cu regresia de
 * polling, în `reports.spec.ts`; aici avem nevoie doar de UN email interceptat existent.
 */
test.use({ storageState: authFile('manager') });

const BASE = '/marlin';
const SENT_EMAILS_URL = `${BASE}/settings/sent-emails`;

const ts = Date.now();
const REPORT_NAME = `E2E Sent Emails Report ${ts}`;
const RECIPIENT = `sent-emails-e2e-${ts}@example.com`;
const SUBJECT = `Your report is ready: ${REPORT_NAME}`;

interface ReportShowProps {
    runs: Array<{ status: string }>;
}

test('jurnalul arată emailul interceptat cu starea corectă; panoul de detaliu inline nu acoperă controlul următor după Tab', async ({ page }) => {
    test.setTimeout(60_000);

    const reportId = await createReport(page, BASE, { name: REPORT_NAME, reportType: 'deal_velocity', recipients: [RECIPIENT] });
    await runReportNow(page, BASE, reportId);

    // Așteaptă finalizarea job-urilor de coadă (`GenerateReportJob` → `DeliverReportJob`,
    // ambele pe coada `default`) — citit direct din props-urile Inertia ale
    // `Reports/Show`, NU prin polling-ul din UI (deja verificat separat, `reports.spec.ts`):
    // acest fișier testează jurnalul de email, nu regresia de polling.
    await expect
        .poll(async () => (await inertiaPageProps<ReportShowProps>(page, `${BASE}/reports/${reportId}`)).runs[0]?.status, { timeout: 30_000 })
        .toBe('success');

    await page.goto(SENT_EMAILS_URL);
    await page.getByRole('heading', { name: 'Sent emails', level: 1 }).waitFor();

    const emailRow = page.locator('table tbody tr').filter({ hasText: SUBJECT });
    await expect(emailRow).toHaveCount(1);
    await expect(emailRow).toContainText('Intercepted');

    // Nu e scenariul de redactare (vezi docblock-ul fișierului) — un email de raport n-are
    // niciun link cu token, deci `redacted` trebuie să rămână fals, nu doar „nu l-am verificat".
    await expect(emailRow.getByText('Link redacted')).toHaveCount(0);

    // ACELAȘI buton comută „View"/„Hide" (numele accesibil se schimbă cu `expanded` —
    // `EmailRow`, `Settings/SentEmails/Index.tsx`), deci locatorul de mai jos trebuie să
    // recunoască AMBELE stări, nu doar cea inițială — altfel o interogare ulterioară (după
    // clic) n-ar mai găsi nimic și ar aștepta la infinit un „View email" care nu mai există.
    const toggleButton = emailRow.getByRole('button', { name: /^(View|Hide) email/ });
    const detailId = await toggleButton.getAttribute('aria-controls');
    await toggleButton.click();

    // Detaliul e o extindere INLINE (`aria-expanded`/`aria-controls`, `Settings/SentEmails/Index.tsx`
    // — niciun `<dialog>`), deci recipientul apare într-un al doilea `<tr>`, imediat după cel
    // găsit mai sus.
    const detailRow = page.locator(`#${detailId}`);
    await expect(detailRow).toBeVisible();
    await expect(detailRow).toContainText(RECIPIENT);
    await expect(detailRow.getByText('intercepted', { exact: true })).toBeVisible();
    await expect(toggleButton).toHaveAccessibleName(/^Hide email/);

    // SC 2.4.11 (Focus Not Obscured) — golul explicit semnalat pentru Faza 4: panoul de
    // detaliu rămâne deschis după Tab (nimic din `EmailRow` îl închide la ieșirea din
    // declanșator), deci controlul următor din ordinea de tab nu trebuie să fie ascuns sub
    // el. Fiind o extindere INLINE (parte din fluxul normal al tabelului, nu un overlay
    // poziționat absolut ca panoul de coloane), se așteaptă „neacoperit" — verificat, nu
    // presupus, cu aceeași tehnică `elementFromPoint` ca `columns.spec.ts`.
    //
    // Controlul „următor" e butonul „View" al rândului imediat următor (sortare implicită
    // `-created_at`, `SentEmailList::defaultSort()`): există garantat, fiindcă acest fișier
    // rulează DUPĂ `reports.spec.ts` (ordine alfabetică de fișiere, `workers: 1`), care a
    // scris deja cel puțin un rând în jurnal.
    await toggleButton.focus();
    await page.keyboard.press('Tab');
    const isObscured = await page.evaluate(() => {
        const el = document.activeElement;
        if (!el || el === document.body) {
            return true;
        }

        const rect = el.getBoundingClientRect();
        const atCenter = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);

        return !(atCenter === el || (atCenter?.contains(el) ?? false) || el.contains(atCenter));
    });
    expect(isObscured, 'controlul de după declanșator nu trebuie acoperit de panoul de detaliu încă deschis').toBe(false);

    // Închide din nou, de la tastatură — declanșatorul rămâne focusabil (buton simplu, fără
    // `disabled` nativ), focusul nu are voie să cadă pe `<body>`.
    await toggleButton.focus();
    await page.keyboard.press('Enter');
    await expect(detailRow).toBeHidden();
    expect(await page.evaluate(() => document.activeElement?.tagName ?? null), 'focusul nu trebuie să cadă pe <body> după închiderea panoului').not.toBe(
        'BODY',
    );
});

/** Gardă dedicată (`sent_emails.view`, distinctă de `settings.view`) — Agent și Viewer n-o au. */
test.describe('RBAC — Sent Emails', () => {
    const rolesWithoutAccess: DemoRole[] = ['agent', 'viewer'];

    for (const role of rolesWithoutAccess) {
        test.describe(DEMO_ROLE_LABELS[role], () => {
            test.use({ storageState: authFile(role) });

            test('refuz (403) pe linkul direct', { tag: ['@smoke'] }, async ({ page }) => {
                const response = await page.goto(SENT_EMAILS_URL);
                expect(response?.status()).toBe(403);
            });
        });
    }
});
