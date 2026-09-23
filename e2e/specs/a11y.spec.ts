import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import type { Result } from 'axe-core';
import { firstRowId } from '../support/api';
import { authFile } from '../support/auth';
import { APP_HOST } from '../support/env';
import { setUserTheme } from '../support/theme';

/**
 * §20.3 / §24.4 — axe-core pe ecranele principale, PE AMBELE TEME: o violare de
 * contrast apare exact într-una dintre ele (tokens diferite per temă,
 * `.ai/rules/frontend.md`), deci o scanare pe o singură temă ratează jumătate
 * din suprafață.
 *
 * --- TEST-10 (audit 2026-09-23, §4 a11y / §11 teste) — extindere de acoperire -----------
 *
 * Starea dinainte scana 9 ecrane × 2 teme, dar bloca DOAR pe `impact: critical` — regula
 * `label-content-name-mismatch` (SC 2.5.3) e `serious`, deci trecea „verde" cu încălcări
 * reale pe Settings/Members și DataExport, iar ecranele Settings nu erau scanate deloc.
 * Erorile de formular neanunțate (focus nemutat, fără `role="alert"`) nu aveau NICIO
 * scanare, indiferent de prag: axe-core nu verifică ordinea de focus și nici dacă un text
 * e ANUNȚAT de un cititor de ecran — doar asertări manuale pot verifica asta (vezi
 * `test.describe('Formulare — anunțarea erorilor de validare (422)')` mai jos).
 *
 * Trei schimbări, în acest fișier:
 *  1. Ecrane noi: tot Settings/*, Orders/Invoices/Products/Deals (listă + Show), Login
 *     anonim, `/privacy` + `/terms` (pagini publice noi, adăugate în paralel de alt lot —
 *     vezi „Excluderi și goluri DELIBERATE" mai jos pentru ce se întâmplă dacă nu există
 *     încă la momentul rulării).
 *  2. Stări noi, nu doar ecrane de bază: un formular Create după submit gol (422), un
 *     `ConfirmDialog` deschis, un dialog de formular deschis (Invite member) — fiecare e
 *     tot o „țintă" (`AxeTarget`) în bucla de mai jos, scanată pe AMBELE teme la fel ca
 *     orice pagină.
 *  3. Criteriul de eșec: blochează acum pe `critical` ȘI `serious` (nu doar `critical`).
 *     O violare `serious` REALĂ, ne-motivată, nu se ascunde prin slăbirea globală a
 *     pragului — `KNOWN_EXCLUSIONS` de mai jos e o listă ALBĂ, per (țintă, regulă axe),
 *     fiecare rând motivat individual, în stilul `modelsNeverExposedThroughAUrl()` din
 *     `tests/Unit/ArchitectureTest.php`: o țintă/regulă NOUĂ e implicit VERIFICATĂ, nu
 *     implicit ignorată.
 *
 * Trei seturi de ținte, roluri DIFERITE, deliberat NU un singur `test.use()` de fișier:
 *  - Manager (`MANAGER_TARGETS`) — ca înainte: singurul rol, în afară de Owner, care vede
 *    interfața COMPLETĂ pe ecranele operaționale (butoane de acțiune incluse).
 *  - Owner (`OWNER_TARGETS`) — Billing/Shipping/Webhook health sunt Owner-only
 *    (`App\Support\Permissions::forRoles()` exclude `billing.*`/`carrier_settings.*`
 *    pentru Manager, verificat direct în cod, nu presupus) — Manager ar primi 403/redirect,
 *    nu ecranul de scanat.
 *  - Anonim (`ANON_TARGETS`) — Login e sub middleware `guest` (un cont autentificat e
 *    redirecționat departe de `/login`); `/privacy`/`/terms` sunt deliberat ÎN AFARA
 *    grupului `guest` (routes/web.php: „un vizitator autentificat trebuie să le poată
 *    deschide la fel"), dar tot au sens scanate anonim — e calea de acces REALĂ a unui
 *    vizitator care dă click în footer înainte de a se autentifica.
 */

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'];

/**
 * Corectură faza 2 (code review) — `label-content-name-mismatch` (SC 2.5.3, Label in
 * Name — exact regula pe care audit-ul o găsise încălcată pe Settings/Members și
 * DataExport, TEST-10) e taggată `experimental` în axe-core 4.13
 * (`node_modules/axe-core/axe.js`, ~L32994: `tags: [..., 'wcag21a', 'wcag253', ...,
 * 'experimental']`), DEȘI are ȘI tag-urile `wcag21a`/`wcag253` din `WCAG_TAGS` de mai
 * sus. NU e suficient s-o ceri prin tag: `Audit._init()` pune
 * `tagExclude = ['experimental', 'deprecated']` (~L29654), iar `matchTags()` (~L20541)
 * scade din `exclude` doar tag-urile care apar EXPLICIT în `include` — un
 * `withTags(WCAG_TAGS)` simplu (fără `'experimental'` în listă) o exclude necondiționat,
 * INDIFERENT dacă `wcag21a`/`wcag253` se potrivesc. Verificat direct, nu presupus:
 * `axe._audit.rules.find(r => r.id === 'label-content-name-mismatch').enabled === true`
 * la nivel de regulă, dar tot exclusă la rulare din cauza tag-ului.
 *
 * Fix-ul corect e `options.rules[id].enabled`, NU `'experimental'` adăugat în
 * `WCAG_TAGS`: `ruleShouldRun()` (~L20569) verifică `ruleOptions.enabled` ÎNAINTE de
 * `matchTags()`, deci ocolește `tagExclude` DOAR pentru regula asta — adăugarea
 * `'experimental'` la `WCAG_TAGS` ar fi pornit ORICE regulă experimentală care se
 * potrivește cu vreun tag WCAG deja cerut (semnal mult mai zgomotos, nemotivat aici).
 *
 * `AxeBuilder#options()` (`@axe-core/playwright` 4.13, `dist/index.js` ~L170)
 * ÎNLOCUIEȘTE tot `this.option`, nu îl îmbină cu `withTags()` — de-asta `runOnly` și
 * `rules` intră ÎMPREUNĂ, într-un singur apel `.options({...})`, mai jos în
 * `runAxeCheck()`, nu `withTags(WCAG_TAGS).options({ rules: ... })` (al doilea apel ar
 * fi șters `runOnly`-ul pus de primul).
 *
 * NU scoate acest bloc/opțiunea ca „redundant(ă)" — fără el, regula nu rulează NICIODATĂ,
 * indiferent de `WCAG_TAGS`.
 */
const RUN_OPTIONS = {
    runOnly: { type: 'tag' as const, values: WCAG_TAGS },
    rules: { 'label-content-name-mismatch': { enabled: true } },
};

type Theme = 'dark' | 'light';

const THEMES: Theme[] = ['dark', 'light'];

const BASE = '/marlin';

interface AxeTarget {
    label: string;
    /**
     * Navighează la ecran/stare ȘI așteaptă „gata încărcat" — un singur pas, nu
     * `path` + `waitFor` separate: stările noi (dialog deschis, formular după 422) au
     * nevoie de interacțiune ÎNAINTE ca ecranul să fie „gata", iar id-urile de Show
     * (Orders/Invoices/Products/Deals) se citesc dintr-un rând real (`firstRowId`), nu
     * se hardcodează.
     */
    goto: (page: Page) => Promise<void>;
}

/**
 * Excluderi explicite, per (țintă, regulă axe) — NU o slăbire globală a pragului
 * `serious` (asta ar anula exact scopul TEST-10). Listă ALBĂ, goală până când o rulare
 * reală descoperă o violare `serious` REALĂ, ÎN AFARA suprafeței pe care lucrează
 * celelalte trei loturi paralele (`resources/js/**`, `routes/web.php`, cataloagele) — dacă
 * apare una, se adaugă AICI, cu motivul, nu se ridică pragul înapoi la `critical`.
 */
interface AxeExclusion {
    target: string;
    ruleId: string;
    reason: string;
}

const KNOWN_EXCLUSIONS: AxeExclusion[] = [];

function isExcluded(targetLabel: string, ruleId: string): boolean {
    return KNOWN_EXCLUSIONS.some((exclusion) => exclusion.target === targetLabel && exclusion.ruleId === ruleId);
}

/**
 * §20.3 — Ecranele cu date deferite (FR-PERF-01) au nevoie de un selector de „gata
 * încărcat": `TableSkeleton` e `role="status"`, NICIODATĂ un `<table>` (verificat în
 * `resources/js/Components/TableSkeleton.tsx`) — `getByRole('table').waitFor()` așteaptă
 * deci rândurile REALE, nu skeleton-ul, pe orice listă cu `Inertia::defer()`.
 */
const MANAGER_TARGETS: AxeTarget[] = [
    {
        label: 'Dashboard',
        goto: async (page) => {
            await page.goto(`${BASE}/dashboard`);
            await page.getByRole('region', { name: 'Recent activity' }).waitFor();
        },
    },
    {
        label: 'Accounts list',
        goto: async (page) => {
            await page.goto(`${BASE}/accounts`);
            await page.getByRole('table').waitFor();
        },
    },
    {
        label: 'Contacts list',
        goto: async (page) => {
            await page.goto(`${BASE}/contacts`);
            await page.getByRole('table').waitFor();
        },
    },
    {
        label: 'Pipeline',
        goto: async (page) => {
            await page.goto(`${BASE}/pipeline`);
            await page.getByRole('heading', { level: 1 }).waitFor();
        },
    },
    {
        label: 'Deals kanban',
        goto: async (page) => {
            // Coloanele reale, nu un skeleton: `Deals/Kanban.tsx` NU e deferred (spre
            // deosebire de listele Accounts/Contacts) — `columns` vine sincron din
            // `DealController::board()` — dar prima coloană a pipeline-ului implicit
            // (`New`, poziția 1, `CatalogSeeder::run()`) tot trebuie așteptată explicit:
            // fără asta, axe ar putea scana un `<main>` gol pe randarea inițială.
            await page.goto(`${BASE}/deals/board`);
            await page.getByRole('heading', { name: 'New', level: 2 }).waitFor();
        },
    },
    // Faza 4 — ecranele noi ale acelui lot (§14 import, §16 rapoarte, §22.3 jurnalul de
    // email). Rulează pe DB proaspătă (acest fișier e primul alfabetic din `specs/`,
    // `workers: 1`): Imports/Reports/Sent Emails sunt încă GOALE aici (`EmptyState`), deci
    // scanarea acoperă structura de bază (titlu, buton „New …", stare goală), NU tabelul cu
    // date real — acela e deja verificat separat, o singură dată, temă implicită (dark), în
    // `imports.spec.ts`/`reports.spec.ts` (ecranul „Show", cu conținut real: pași de mapare,
    // rezultat built-in, istoric de rulări).
    {
        label: 'Imports list',
        goto: async (page) => {
            await page.goto(`${BASE}/imports`);
            await page.getByRole('heading', { name: 'Imports', level: 1 }).waitFor();
        },
    },
    {
        label: 'New import',
        goto: async (page) => {
            await page.goto(`${BASE}/imports/create`);
            await page.getByRole('heading', { name: 'New import' }).waitFor();
        },
    },
    {
        label: 'Reports list',
        goto: async (page) => {
            await page.goto(`${BASE}/reports`);
            await page.getByRole('heading', { name: 'Reports', level: 1 }).waitFor();
        },
    },
    {
        label: 'New report',
        goto: async (page) => {
            await page.goto(`${BASE}/reports/create`);
            await page.getByRole('heading', { name: 'New report' }).waitFor();
        },
    },
    {
        label: 'Sent emails',
        goto: async (page) => {
            // Deferred (`Inertia::defer`, `SentEmailController::index()`) — axe trebuie să
            // vadă starea REZOLVATĂ (tabel SAU mesajul de listă goală), nu `TableSkeleton`.
            await page.goto(`${BASE}/settings/sent-emails`);
            await page
                .locator('table')
                .or(page.getByText('No emails have been sent or intercepted yet.'))
                .first()
                .waitFor();
        },
    },

    // --- TEST-10, Settings/* (niciunul scanat înainte) ----------------------------------
    {
        label: 'Settings index',
        goto: async (page) => {
            await page.goto(`${BASE}/settings`);
            await page.getByRole('heading', { level: 1 }).waitFor();
        },
    },
    {
        label: 'Settings — Members',
        goto: async (page) => {
            await page.goto(`${BASE}/settings/members`);
            await page.getByRole('table').waitFor();
        },
    },
    {
        // Substituie ținta cerută inițial („dialogul Deactivate member deschis") —
        // IMPOSIBIL de atins în acest mediu, vezi „Excluderi și goluri DELIBERATE" mai
        // jos. Rămâne totuși o scanare REALĂ a unui dialog deschis pe ACEEAȘI pagină,
        // din ACEEAȘI familie de componentă (`<dialog>` nativ, `showModal()`).
        label: 'Settings — Members — invite dialog',
        goto: async (page) => {
            await page.goto(`${BASE}/settings/members`);
            await page.getByRole('table').waitFor();
            await page.getByRole('button', { name: 'Invite member' }).click();
            await page.getByRole('dialog', { name: 'Invite a member' }).waitFor();
        },
    },
    {
        label: 'Settings — Data export',
        goto: async (page) => {
            await page.goto(`${BASE}/settings/data-export`);
            await page.getByRole('heading', { level: 1 }).waitFor();
        },
    },
    {
        label: 'Settings — Preferences',
        goto: async (page) => {
            await page.goto(`${BASE}/settings/preferences`);
            await page.getByRole('heading', { level: 1 }).waitFor();
        },
    },
    {
        label: 'Settings — API tokens',
        goto: async (page) => {
            await page.goto(`${BASE}/settings/api-tokens`);
            await page.getByRole('heading', { level: 1 }).waitFor();
        },
    },

    // --- TEST-10, Orders/Invoices/Products/Deals — listă + Show -------------------------
    {
        label: 'Orders list',
        goto: async (page) => {
            await page.goto(`${BASE}/orders`);
            await page.getByRole('table').waitFor();
        },
    },
    {
        label: 'Orders show',
        goto: async (page) => {
            const id = await firstRowId(page, `${BASE}/orders`);
            await page.goto(`${BASE}/orders/${id}`);
            await page.getByRole('heading', { level: 1 }).waitFor();
        },
    },
    {
        label: 'Invoices list',
        goto: async (page) => {
            await page.goto(`${BASE}/invoices`);
            await page.getByRole('table').waitFor();
        },
    },
    {
        label: 'Invoices show',
        goto: async (page) => {
            const id = await firstRowId(page, `${BASE}/invoices`);
            await page.goto(`${BASE}/invoices/${id}`);
            await page.getByRole('heading', { level: 1 }).waitFor();
        },
    },
    {
        label: 'Products list',
        goto: async (page) => {
            await page.goto(`${BASE}/products`);
            await page.getByRole('table').waitFor();
        },
    },
    {
        label: 'Products show',
        goto: async (page) => {
            const id = await firstRowId(page, `${BASE}/products`);
            await page.goto(`${BASE}/products/${id}`);
            await page.getByRole('heading', { level: 1 }).waitFor();
        },
    },
    {
        // Un singur `ConfirmDialog` scanat, deliberat — vezi „Excluderi și goluri
        // DELIBERATE" mai jos pentru motivul „nu toate cele ~8 din aplicație".
        label: 'Products show — delete confirm dialog',
        goto: async (page) => {
            const id = await firstRowId(page, `${BASE}/products`);
            await page.goto(`${BASE}/products/${id}`);
            await page.getByRole('heading', { level: 1 }).waitFor();
            await page.getByRole('button', { name: 'Delete' }).click();
            await page.getByRole('dialog').waitFor();
        },
    },
    {
        // Un singur formular Create în stare de eroare, deliberat — vezi „Excluderi și
        // goluri DELIBERATE" mai jos. `unit_of_measure` are deja o valoare implicită
        // (`each`), deci submitul gol lasă UN SINGUR câmp invalid (`name`), determinist.
        label: 'Products create — validation errors (empty submit)',
        goto: async (page) => {
            await page.goto(`${BASE}/products/create`);
            await page.getByRole('heading', { level: 1 }).waitFor();
            await page.getByRole('button', { name: 'Create product' }).click();
            await page.locator('[aria-invalid="true"]').first().waitFor();
        },
    },
    {
        label: 'Deals list',
        goto: async (page) => {
            await page.goto(`${BASE}/deals`);
            await page.getByRole('table').waitFor();
        },
    },
    {
        label: 'Deals show',
        goto: async (page) => {
            const id = await firstRowId(page, `${BASE}/deals`);
            await page.goto(`${BASE}/deals/${id}`);
            await page.getByRole('heading', { level: 1 }).waitFor();
        },
    },
];

/**
 * Owner-only: `Permissions::forRoles()` exclude explicit `billing.view`, `billing.manage`,
 * `carrier_settings.view`, `carrier_settings.manage` pentru Manager (`app/Support/
 * Permissions.php`) — un Manager ar primi 403/redirect pe aceste trei rute, nu ecranul de
 * scanat, deci NU pot sta în `MANAGER_TARGETS`.
 */
const OWNER_TARGETS: AxeTarget[] = [
    {
        label: 'Settings — Billing',
        goto: async (page) => {
            await page.goto(`${BASE}/settings/billing`);
            await page.getByRole('heading', { level: 1 }).waitFor();
        },
    },
    {
        label: 'Settings — Shipping',
        goto: async (page) => {
            await page.goto(`${BASE}/settings/shipping`);
            await page.getByRole('heading', { level: 1 }).waitFor();
        },
    },
    {
        label: 'Settings — Webhook health',
        goto: async (page) => {
            await page.goto(`${BASE}/settings/webhooks`);
            await page.getByRole('heading', { level: 1 }).waitFor();
        },
    },
];

/**
 * Vizitator anonim — `/login` e sub middleware `guest` (un cont autentificat e
 * redirecționat spre dashboard, nu vede formularul); `/privacy`/`/terms` sunt public
 * accesibile oricui, dar scanate aici anonim fiindcă ăsta e drumul REAL al unui vizitator
 * care dă click în footer înainte de autentificare (`GuestLayout.tsx`).
 */
const ANON_TARGETS: AxeTarget[] = [
    {
        label: 'Login',
        goto: async (page) => {
            await page.goto('/login');
            await page.getByRole('heading', { level: 1 }).waitFor();
        },
    },
    {
        // GDPR-06 (audit 2026-09-23, §3) — adăugată în paralel, în alt lot. Catalogul
        // (`resources/js/locales/en/legal.json`) și ruta (`routes/web.php`,
        // `legal.privacy`) existau deja la scrierea acestui fișier; componenta React
        // (`resources/js/Pages/Legal/Privacy.tsx`) NU — vezi „Excluderi și goluri
        // DELIBERATE" mai jos pentru ce înseamnă asta la rularea de bază.
        label: 'Privacy policy',
        goto: async (page) => {
            await page.goto('/privacy');
            await page.getByRole('heading', { name: 'Privacy Policy', level: 1 }).waitFor();
        },
    },
    {
        label: 'Terms of use',
        goto: async (page) => {
            await page.goto('/terms');
            await page.getByRole('heading', { name: 'Terms of Use', level: 1 }).waitFor();
        },
    },
];

/**
 * P1 (code review) — un cookie SINGUR nu ajunge: `ThemePreference::resolveForRequest()`
 * citește ÎNTÂI alegerea explicită a utilizatorului AUTENTIFICAT curent (`users.theme`),
 * ÎNAINTE de cookie — toate conturile demo au deja `theme='dark'` explicit (migrația
 * `2026_09_13_120000_change_users_theme_default_to_dark.php`, implicit nou pentru
 * TOȚI utilizatorii, seederele nu trec `theme`), deci cookie-ul singur era IGNORAT: ambele
 * treceri „dark" și „light" randau de fapt tema închisă — verificat empiric, vezi raportul
 * agentului. `setUserTheme()` (`e2e/support/theme.ts`) schimbă preferința REALĂ, prin
 * `PATCH /preferences/theme`. Cookie-ul rămâne pus și el (nu strică, dar nu mai e sursa de
 * adevăr aici).
 *
 * Pentru vizitatorul ANONIM (`ANON_TARGETS`), pasul 1 nu se aplică (n-are cont autentificat
 * de verificat) — cookie-ul SINGUR e suficient, `anonymousTheme()` mai jos.
 */
async function setTheme(page: Page, theme: Theme): Promise<void> {
    await setUserTheme(page, theme);
    await page.context().addCookies([{ name: 'theme', value: theme, domain: APP_HOST, path: '/' }]);
}

async function anonymousTheme(page: Page, theme: Theme): Promise<void> {
    await page.context().addCookies([{ name: 'theme', value: theme, domain: APP_HOST, path: '/' }]);
}

/**
 * Dovadă că tema chiar s-a aplicat PE ACEST răspuns (FR-PREF-03 — randată server-side, din
 * `resources/views/app.blade.php`: `<html class="{{ $theme === 'dark' ? 'dark' : '' }}">`) —
 * fără ea, testul poate trece „verde" scanând din nou tema greșită, exact regresia găsită la
 * review.
 */
async function expectHtmlThemeClass(page: Page, theme: Theme): Promise<void> {
    await expect(page.locator('html')).toHaveClass(theme === 'dark' ? 'dark' : '');
}

function slug(label: string): string {
    return label.toLowerCase().replace(/[^a-z0-9]+/g, '-');
}

function formatViolations(violations: Result[]): string {
    if (violations.length === 0) {
        return '';
    }

    return violations
        .map((violation) => {
            const nodes = violation.nodes.map((node) => `    - ${node.target.join(' ')}`).join('\n');

            return `[${violation.impact}] ${violation.id} — ${violation.help}\n${nodes}`;
        })
        .join('\n');
}

/**
 * Rulează scanarea axe pentru O țintă, pe tema curentă — factorizat, ca cele trei bucle
 * (Manager/Owner/Anonim) să nu tripleze aceeași logică de asertare.
 *
 * Criteriul de eșec (TEST-10): `critical` ȘI `serious` blochează, minus excluderile
 * EXPLICITE din `KNOWN_EXCLUSIONS` — o excludere rămâne vizibilă în output (`console.warn`),
 * nu dispare tăcut.
 */
async function runAxeCheck(page: Page, testInfo: TestInfo, target: AxeTarget, theme: Theme): Promise<void> {
    await target.goto(page);
    await expectHtmlThemeClass(page, theme);

    const results = await new AxeBuilder({ page }).options(RUN_OPTIONS).analyze();
    const relevant = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
    const excluded = relevant.filter((violation) => isExcluded(target.label, violation.id));
    const blocking = relevant.filter((violation) => !isExcluded(target.label, violation.id));

    await testInfo.attach(`axe-${slug(target.label)}-${theme}.json`, {
        body: JSON.stringify(results.violations, null, 2),
        contentType: 'application/json',
    });

    if (excluded.length > 0) {
        console.warn(`[axe] ${target.label} (${theme}): ${excluded.length} violare(i) EXCLUSĂ(E) explicit (KNOWN_EXCLUSIONS):\n${formatViolations(excluded)}`);
    }

    expect(blocking, formatViolations(blocking)).toEqual([]);
}

interface ThemeStrategy {
    apply: (page: Page, theme: Theme) => Promise<void>;
    /**
     * Restaurează `dark` după fiecare test „light": `users.theme` PERSISTĂ pe contul
     * folosit (Manager/Owner), comun tuturor spec-urilor (`workers: 1`, aceeași bază
     * `throughput_e2e`) — fără restaurare, orice spec de după acest fișier care
     * reautentifică același cont ar rula, din greșeală, pe tema deschisă. Vizitatorul
     * anonim n-are nimic de restaurat (niciun cont persistă alegerea).
     */
    restoreAfterLight?: (page: Page) => Promise<void>;
}

const authenticatedStrategy: ThemeStrategy = {
    apply: setTheme,
    restoreAfterLight: (page) => setUserTheme(page, 'dark'),
};

const anonymousStrategy: ThemeStrategy = {
    apply: anonymousTheme,
};

function runThemeMatrix(targets: AxeTarget[], strategy: ThemeStrategy, smokeLabel?: string): void {
    for (const theme of THEMES) {
        test.describe(`temă ${theme}`, () => {
            test.beforeEach(async ({ page }) => {
                await strategy.apply(page, theme);
            });

            if (theme === 'light' && strategy.restoreAfterLight) {
                test.afterEach(async ({ page }) => {
                    await strategy.restoreAfterLight!(page);
                });
            }

            for (const target of targets) {
                // Subset @smoke (PR-uri): un singur ecran, tema implicită (închisă) —
                // suficient să prindă o regresie de accesibilitate introdusă de un PR,
                // fără costul întregii matrice.
                const tag = theme === 'dark' && target.label === smokeLabel ? ['@smoke'] : [];

                test(`${target.label} — 0 violări critice/serioase (axe, WCAG 2.x A/AA)`, { tag }, async ({ page }, testInfo) => {
                    await runAxeCheck(page, testInfo, target, theme);
                });
            }
        });
    }
}

test.describe('Manager', () => {
    test.use({ storageState: authFile('manager') });

    runThemeMatrix(MANAGER_TARGETS, authenticatedStrategy, 'Dashboard');
});

test.describe('Owner — ecrane Owner-only (Billing/Shipping/Webhook health)', () => {
    test.use({ storageState: authFile('owner') });

    runThemeMatrix(OWNER_TARGETS, authenticatedStrategy);
});

test.describe('Vizitator anonim', () => {
    // Șterge storageState-ul implicit (dacă un proiect l-ar seta) — formă documentată de
    // Playwright pentru „testează ca utilizator neautentificat": `/login` e sub `guest`,
    // un cont autentificat ar fi redirecționat departe de formular.
    test.use({ storageState: { cookies: [], origins: [] } });

    runThemeMatrix(ANON_TARGETS, anonymousStrategy);
});

/**
 * TEST-10 (audit 2026-09-23, §11) — „erorile de formular neanunțate". NU e o scanare axe:
 * axe-core nu verifică ordinea de focus după submit, nici dacă un text e ANUNȚAT de un
 * cititor de ecran (vs. doar afișat vizual) — doar asertări manuale, directe pe cerința
 * din audit, pot verifica asta.
 *
 * Un singur formular (`Products/Create`), reprezentativ — vezi „Excluderi și goluri
 * DELIBERATE" mai jos pentru motivul „unul singur, nu toate câte există".
 */
test.describe('Formulare — anunțarea erorilor de validare (422)', () => {
    test.use({ storageState: authFile('manager') });

    test('Products/Create — submit gol: focus pe primul câmp invalid + eroare anunțată (role="alert")', async ({ page }) => {
        await page.goto(`${BASE}/products/create`);
        await page.getByRole('heading', { level: 1 }).waitFor();

        await page.getByRole('button', { name: 'Create product' }).click();

        const firstInvalid = page.locator('[aria-invalid="true"]').first();
        await expect(firstInvalid, '422 ar trebui să marcheze cel puțin un câmp invalid (Product name, gol)').toBeVisible();

        // SC 2.4.3 (Focus Order) / SC 3.3.1 (Error Identification) — un utilizator de
        // tastatură/cititor de ecran trebuie dus DIRECT pe câmpul greșit, nu lăsat pe
        // butonul de submit ca să caute singur eroarea în pagină.
        await expect.soft(firstInvalid, 'focusul ar trebui mutat pe primul câmp invalid după submit (Field.tsx / ProductForm.tsx)').toBeFocused();

        const describedBy = (await firstInvalid.getAttribute('aria-describedby')) ?? '';
        const errorId = describedBy.split(/\s+/).filter(Boolean).pop();
        expect(errorId, 'câmpul invalid trebuie să aibă aria-describedby către eroarea lui (Field.tsx)').toBeTruthy();

        // SC 4.1.3 (Status Messages) — un cititor de ecran anunță un `role="alert"` chiar
        // dacă utilizatorul nu are focusul pe el; un `<p>` simplu (starea găsită la audit,
        // `Field.tsx` linia erorii) rămâne tăcut până la următoarea navigare cu Tab.
        await expect.soft(page.locator(`#${errorId}`), 'eroarea de validare ar trebui să aibă role="alert" (Field.tsx)').toHaveAttribute('role', 'alert');
    });
});

/**
 * Excluderi și goluri DELIBERATE — în stilul `modelsNeverExposedThroughAUrl()` din
 * `tests/Unit/ArchitectureTest.php`: fiecare rând e o decizie EXPLICITĂ, verificată, nu o
 * omisiune tăcută. O țintă/stare NOUĂ, care nu apare aici, e implicit așteptată să fie
 * ADĂUGATĂ la scanare, nu presupusă acoperită.
 *
 * 1. Dialogul „Deactivate member" (cerut inițial pentru Settings/Members) — IMPOSIBIL de
 *    deschis în acest mediu, nu doar „scump": `member.canDeactivate` e necondiționat
 *    `false` cât `DEMO_MODE=true` (`app/Http/Controllers/Web/Settings/MembersController.php`,
 *    `DemoMode::allows('members.deactivate')`), verificat empiric în `members.spec.ts`
 *    („0 butoane Deactivate, orice rol, Owner inclus"). Aceeași gardă de mediu oprește și
 *    `canUpdateRole` (`members.change-role`), deci dialogul „Change role" e la fel de
 *    inaccesibil. Suita ÎNTREAGĂ are nevoie de `DEMO_MODE=true` (altfel `/login/demo/{role}`
 *    dă 404 și `auth.setup.ts` n-are ce apăsa) — nicio combinație de rol/cont, cu
 *    mecanismul de autentificare al acestei suite, nu ocolește garda. Substituit cu
 *    „Settings — Members — invite dialog" (aceeași pagină, aceeași familie de componentă
 *    `<dialog>` nativ) — coverage real, chiar dacă nu identic cu cererea literală.
 * 2. UN SINGUR `ConfirmDialog` scanat (Products show → Delete), nu toate cele ~8 din
 *    aplicație (Orders confirm/cancel/ship, Invoices void/send, Deals lost-reason etc.):
 *    toate împart EXACT aceeași componentă (`resources/js/Components/ConfirmDialog.tsx`) —
 *    un defect de focus-trap/`aria-labelledby` ar fi în componenta comună, nu per apelant.
 *    Scanarea celorlalte 7 ar dubla costul de CI (7 ținte × 2 teme) pentru semnal marginal.
 * 3. UN SINGUR formular Create în stare de eroare 422 scanat (Products/Create), nu toate
 *    formularele Create din aplicație: toate împart aceeași pereche `Field.tsx` +
 *    `useForm().errors` — eșantion reprezentativ, nu acoperire completă.
 * 4. Starea alternativă „subscription canceled" a paginii Billing
 *    (`settings:billing.canceledTitle`, `Settings/Billing/Index.tsx` — ramura
 *    `subscription.accessLevel === 'blocked'`, care NU randează niciun `<h1>`) nu e
 *    scanată: tenantul demo folosit de toată suita nu e în starea asta (dacă ar fi,
 *    majoritatea celorlalte ținte ar fi oricum blocate de `EnsureSubscriptionAccess`) —
 *    ar avea nevoie de un tenant/fixture propriu, cost separat, în afara scopului acestei
 *    extinderi.
 * 5. „Settings — Data export" scanează ecranul, NU linkul „Download" al unui export deja
 *    generat (A11Y-04, audit §4): niciun seeder nu creează rânduri `DataExportRequest`
 *    (verificat — `grep -rl 'DataExportRequest::' database/seeders/` nu întoarce nimic),
 *    deci tabelul e GOL pe DB proaspătă (acest fișier rulează primul alfabetic din
 *    `specs/`, `workers: 1`) — nu există niciun link „Download" de scanat la momentul
 *    acestei rulări. Fixture-ul ar cere fie un export REAL (job asincron, cost de CI),
 *    fie un INSERT direct în `data_export_requests` doar pentru acest test — ambele în
 *    afara scopului acestei extinderi. Rămâne un gol real, nu doar una teoretică.
 */
