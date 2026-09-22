import { expect, test } from '@playwright/test';
import { authFile } from '../support/auth';
import { setUserLocale } from '../support/locale';

/**
 * Lot I18N, Val 5 — subsetul `@fr` (specs.md §15.8, ADR-022, plan-implementare.md „Lot I18N").
 *
 * Tag `@fr`, NICIODATĂ `@smoke`: mecanismul din `.github/workflows/ci.yml` (linia ~321 la
 * scrierea acestui fișier) comută între `--grep @smoke` pe PR și suita COMPLETĂ (fără
 * `--grep`) pe push la `main`/tag-uri. Un test cu `@fr` și fără `@smoke` NU e prins de
 * `--grep @smoke`, deci nu rulează pe niciun PR — dar E prins de suita completă, deci rulează
 * pe fiecare push la `main`. Verificat citind pasul, nu presupus — nicio modificare de
 * workflow nu era nevoie (vezi comentariul adăugat acolo).
 *
 * --- De ce ACEST fișier NU capătă un `storageState` francez separat -----------------------
 *
 * `e2e/setup/auth.setup.ts` rămâne DELIBERAT pe engleză (butonul „Log in as …" e cuplat pe
 * text literal, cu o aserțiune explicită `lang=en`) — a-l traduce sau a-l face dependent de
 * limbă ar pica AUTENTIFICAREA celor 69 de teste existente, nu doar subsetul de-aici.
 * Conturile demo (`users.locale`) au implicit `'en'` (migrația), iar `APP_LOCALE=en` ține
 * determinist orice cerere ANONIMĂ — vezi docblock-ul „Lot I18N, Val 1" din
 * `playwright.config.ts`.
 *
 * Testele de mai jos comută limba DUPĂ autentificare, pe un cont demo EXISTENT (`owner`),
 * folosind fie mecanismul real din UI (`LocaleToggle`, primul test — exact fluxul pe care un
 * utilizator îl parcurge), fie `setUserLocale()` (`PATCH /preferences/locale` direct — al
 * doilea și al treilea test, unde comutarea în sine NU e ce se verifică, ci EFECTUL ei).
 * Motivul pentru care NU s-a creat un cont demo francez separat (a cincea opțiune din task):
 * ar fi însemnat un al cincilea `storageState`, scris de un al cincilea test în
 * `auth.setup.ts` — fișier pe care task-ul interzice explicit să-l ating — SAU un proiect
 * Playwright separat cu propriul `setup`, cost disproporționat față de o simplă comutare
 * după autentificare, care e oricum fluxul REAL (niciun cont din specs.md nu se naște
 * francez; `users.locale` pornește mereu pe `'en'`, migrația).
 *
 * --- Starea PARTAJATĂ e capcana reală aici, nu comutarea în sine --------------------------
 *
 * `owner` e refolosit de ~10 alte fișiere de spec (`workspace-switch`, `members`,
 * `bulk-cancel`, `roles`, …), toate presupunând engleză. `users.locale` e o coloană pe
 * rândul din bază, NU pe `storageState` — o schimbare aici e vizibilă instant pentru ORICE
 * test ulterior care redeschide o sesiune a aceluiași cont (`workers: 1`, execuție
 * secvențială, dar ordinea fișierelor NU garantează că acesta rulează ultimul). De-asta
 * fiecare test își revine pe engleză în `test.afterEach` — NU pe ultima linie a testului: un
 * `expect` picat la mijloc ar sări peste orice cleanup scris „la final", lăsând contul
 * `owner` francez pentru tot restul rulării.
 */
test.use({ storageState: authFile('owner') });

test.afterEach(async ({ page }) => {
    await setUserLocale(page, 'en');
});

test(
    'LocaleToggle comută instant, client-side (fără reîncărcare completă) și NU remontează pagina (preserveState: true)',
    { tag: ['@fr'] },
    async ({ page }) => {
        await page.goto('/marlin/settings/preferences');
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        await expect(page.getByRole('heading', { name: 'Language', level: 2 })).toBeVisible();

        // Întârzie ARTIFICIAL răspunsul serverului la PATCH — proba reală că schimbarea de
        // limbă e client-side (`i18n.changeLanguage` + `applyDocumentLocale`,
        // `Components/LocaleToggle.tsx`), nu efectul răspunsului. Dacă traducerea ar depinde
        // de round-trip, eticheta ar rămâne engleză până la eliberarea porții de mai jos.
        let releaseResponse: () => void = () => undefined;
        const responseGate = new Promise<void>((resolve) => {
            releaseResponse = resolve;
        });
        await page.route('**/preferences/locale', async (route) => {
            await responseGate;
            await route.continue();
        });

        // Radioul e `sr-only` (stil identic cu `ThemeToggle` — vezi `LocaleToggle.tsx`),
        // înfășurat într-un `<label>` cu zonă de atingere extinsă care ACOPERĂ vizual
        // input-ul — apăsăm eticheta, nu `input`-ul (`checkByLabel`, `e2e/support/bulk.ts`,
        // documentează exact interceptarea de pointer events pe care `.check()` direct pe
        // `input` o lovește: 325+ reîncercări până la timeout).
        const frRadio = page.getByRole('radio', { name: 'FR', exact: true });
        await frRadio.locator('xpath=ancestor::label[1]').click();

        // Instant — ÎNAINTE ca `PATCH /preferences/locale` să fi apucat să răspundă (poarta
        // de mai sus e încă închisă): `<html lang>` și eticheta tradusă vin din
        // `i18n.changeLanguage()`/`applyDocumentLocale()`, sincron, în handler-ul `select()`.
        await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
        await expect(page.getByRole('heading', { name: 'Langue', level: 2 })).toBeVisible();

        // Contrast deliberat, NU un defect: STAREA BIFATĂ a radioului NU e locală — vine din
        // `auth.user?.locale` (propul Inertia curent), citit direct în `LocaleToggle.tsx`
        // (`const choice = auth.user?.locale ?? 'en'`). Cât timp poarta de mai sus ține
        // răspunsul, propul NU s-a actualizat încă, deci React readuce vizual radioul pe
        // starea VECHE ('en') — deși limba AFIȘATĂ e deja franceză. Cele două lucruri se
        // confirmă separat, exact ca în aplicația reală: textul se schimbă instant, radioul
        // confirmă abia după round-trip.
        await expect(frRadio).not.toBeChecked();

        // NU `page.unroute()` aici: ar intra în cursă cu handler-ul de mai sus, încă „în
        // zbor" între eliberarea porții și `route.continue()` (măsurat — „Route is already
        // handled!"). Un singur handler pe toată durata testului e suficient; nu mai vine
        // nicio altă cerere către ruta asta după acest punct.
        releaseResponse();
        await page.waitForResponse((response) => response.url().includes('/preferences/locale'));

        // Dovada reală a `preserveState: true` (.ai/rules/frontend.md, „Componenta de pagină
        // se remontează la fiecare navigare — `key: Date.now()`"): dacă `router.patch()` NU
        // l-ar folosi, componenta de pagină s-ar remonta la sosirea răspunsului — radioul
        // apăsat ar fi înlocuit cu un element NOU, iar focusul ar cădea pe `<body>`. Rămâne
        // pe radio DOAR dacă pagina n-a fost remontată sub el.
        await expect(frRadio).toBeFocused();
        // Iar acum, cu propul Inertia proaspăt sosit, radioul confirmă și el vizual alegerea.
        await expect(frRadio).toBeChecked();
    },
);

test('preferința de limbă supraviețuiește comutării de workspace (Owner, Marlin → Cascade)', { tag: ['@fr'] }, async ({ page }) => {
    // Comutarea în sine e verificată mai sus (UI) și în Pest (`LocaleTest.php`) — aici
    // contează doar EFECTUL ei peste navigarea reală prin `WorkspaceSwitcher`
    // (`e2e/specs/workspace-switch.spec.ts`, tipar refolosit identic).
    await setUserLocale(page, 'fr');

    await page.goto('/marlin/dashboard');
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    // `dashboard:kpis.openPipelineValue` — placa KPI cea mai lungă a catalogului `fr`
    // (v. `i18n-layout.spec.ts`), aleasă aici tocmai fiindcă e departe de a fi un fallback pe
    // cheia brută dacă namespace-ul `dashboard` n-ar fi încărcat corect.
    await expect(page.getByText('Valeur du pipeline ouvert')).toBeVisible();

    await page.getByRole('button', { name: 'Marlin Fasteners & Supply Co.' }).click();
    await page.getByRole('option', { name: 'Cascade Hydraulic Components' }).click();

    await expect(page).toHaveURL(/\/cascade\/dashboard$/);
    // Preferința e a PERSOANEI, nu a organizației (FR-I18N-01, simetric cu FR-PREF-02) — NU
    // se resetează la comutare, deși workspace-ul (deci și seed-ul KPI-urilor din spate) s-a
    // schimbat complet.
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    await expect(page.getByText('Valeur du pipeline ouvert')).toBeVisible();
});

test('panoul de ajutor încarcă leneș catalogul FR — titlul tradus, nu cheia brută', { tag: ['@fr'] }, async ({ page }) => {
    await setUserLocale(page, 'fr');

    await page.goto('/marlin/dashboard');
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');

    // Deschis de la tastatură — focusul e pe `<body>` imediat după navigare (la fel ca
    // `help-panel.spec.ts`).
    await page.keyboard.press('?');

    // `common:helpPanel.regionLabel` = „Aide" (FR) — vezi `HelpPanel.tsx`, eticheta regiunii
    // NU mai concatenează titlul subiectului de la Valul 4.
    const panel = page.locator('aside[aria-label="Aide"]');
    const heading = panel.getByRole('heading', { level: 2 });
    await expect(heading).toBeFocused();

    // Capcana explicită a Valului 4: dacă `loadHelpCatalog('fr')` n-ar fi rulat (sau
    // `addResourceBundle` ar fi eșuat tăcut), `t('help:topics.dashboard.title')` ar întoarce
    // CHEIA brută, nu un text gol — deci verificăm ȘI titlul tradus, ȘI absența cheii brute.
    await expect(heading).toHaveText('Tableau de bord');
    await expect(heading).not.toHaveText('topics.dashboard.title');

    // Structura fixă (§HelpPanel.tsx) rămâne aceeași, doar titlurile secțiunilor sunt acum
    // franceze — dovadă suplimentară că namespace-ul `common` (deja static în bundle) și
    // `help` (leneș) coexistă corect pe aceeași limbă.
    await expect(panel.getByRole('heading', { name: 'Ce que vous pouvez faire ici' })).toBeVisible();
    await expect(panel.getByRole('heading', { name: 'Règles applicables ici' })).toBeVisible();
});
