import { expect, test } from '@playwright/test';
import { authFile } from '../support/auth';

/**
 * §24.3 / plan §8, Livrabile — Cmd+K (`GlobalSearch.tsx`), EXCLUSIV de la tastatură:
 * deschidere cu `ControlOrMeta+K` (CI rulează pe Linux, `ControlOrMeta` se traduce în
 * `Control` acolo — vezi `playwright.dev/docs/api/class-keyboard`), verificare că starea
 * inițială NU e goală, tastare care întoarce rezultate grupate pe tip, navigare cu
 * săgețile, `Enter` care duce la URL-ul corect, apoi redeschidere + `Esc` care închide cu
 * focusul înapoi pe declanșator.
 *
 * Discrepanță plan ↔ cod, raportată explicit (nu inventată): plan §8 cere „4 tipuri de
 * entități" în rezultate. `GlobalSearchService::search()` (citit direct, nu presupus)
 * interoghează DOAR `accounts`/`contacts`/`deals` — trei tipuri de entitate reale, plus un
 * grup „actions" (comenzi rapide de creare, nu o entitate căutabilă). Produsele intră în
 * căutare abia în Faza 3 (comentariul din `plan-implementare.md` §8 chiar spune asta,
 * „Produsele intră în căutare în Faza 3, odată cu ecranele lor"), deci „4" pare o
 * anticipare a stării de după Faza 3, nu o cerință deja implementabilă acum. Testul de mai
 * jos verifică ce există REALMENTE: minimum 2 tipuri de entitate simultan (Accounts +
 * Deals), ca „grupate pe tip" să fie o verificare reală, nu doar o singură etichetă.
 *
 * Rol: Manager — poate crea toate cele trei tipuri de entități (grupul „Actions" din
 * starea inițială nu e gol), ceea ce satisface „starea inițială nu e goală" fără să
 * depindă de `RecentlyViewed` (populat sau nu de alte teste rulate înainte în aceeași
 * suită — `workers: 1`, dar ordinea de FIȘIERE nu e o garanție pe care merită construit un
 * test).
 */
test.use({ storageState: authFile('manager') });

test('Cmd+K — stare inițială, căutare grupată pe tip, navigare cu tastatura, Esc readuce focusul', async ({ page }) => {
    const probe = `Probe${Date.now()}`;
    const accountName = `${probe} Industries`;
    const dealTitle = `${probe} Renewal`;

    // Două entități care împart același substring de căutare — create prin UI, ca
    // rezultatul căutării de mai jos să depindă de comportamentul REAL al aplicației, nu
    // de date fixate în seed.
    await page.goto('/marlin/accounts/create');
    // `getByRole('textbox', { name })`, NU `getByLabel`: „Account name"/„Title" sunt
    // câmpuri `required` (`Field ... required`), al căror `<label>` conține și un
    // `<span aria-hidden>*</span>` — text BRUT „Account name *", exclus doar din numele
    // accesibil al INPUT-ului, nu din textul etichetei pe care s-ar baza `getByLabel`.
    await page.getByRole('textbox', { name: 'Account name', exact: true }).fill(accountName);
    await page.getByRole('button', { name: 'Create account' }).click();
    // Lookahead negativ, NU doar `[^/?]+$`: fără el, acest assert trecea și pe un 422 de
    // validare rămas pe `/accounts/create` — exact bug-ul găsit aici (vezi fix-ul din
    // `AccountForm.tsx`: `contact` ajungea mereu „prezent" în payload, chiar necompletat).
    await expect(page).toHaveURL(/\/accounts\/(?!create)[^/?]+$/);

    await page.getByRole('link', { name: 'New deal' }).click();
    await expect(page).toHaveURL(/\/deals\/create\?account=/);
    await page.getByRole('textbox', { name: 'Title', exact: true }).fill(dealTitle);
    await page.getByRole('button', { name: 'Create deal' }).click();
    // Așteaptă explicit noul URL ÎNAINTE de a-l citi: `.click()` așteaptă doar acțiunea de
    // clic, nu și vizita Inertia declanșată de ea — citit imediat, `page.url()` putea
    // încă arăta `/deals/create` (rasă câștigată din greșeală în prima rulare a acestui test).
    await page.waitForURL(/\/deals\/(?!create)[^/?]+$/);
    const dealUrl = new URL(page.url()).pathname;

    // Ecran neutru, ca deschiderea paletei să nu depindă de ce pagină specifică era pe
    // ecran înainte (FR-SEARCH-01 — disponibilă „din orice ecran autentificat").
    await page.goto('/marlin/dashboard');

    const trigger = page.getByRole('button', { name: 'Search' });
    const dialog = page.getByRole('dialog', { name: 'Global search' });

    await page.keyboard.press('ControlOrMeta+K');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('combobox')).toBeFocused();

    // Starea inițială (fără termen tastat încă) nu e un ecran gol — grupul „Actions" e
    // garantat non-gol pentru Manager (poate crea conturi/contacte/deals).
    await expect(dialog.getByRole('group', { name: 'Actions' })).toBeVisible();

    await page.keyboard.type(probe);

    const accountsGroup = dialog.getByRole('group', { name: 'Accounts' });
    const dealsGroup = dialog.getByRole('group', { name: 'Deals' });
    await expect(accountsGroup).toBeVisible();
    await expect(dealsGroup).toBeVisible();
    await expect(accountsGroup.getByRole('option', { name: new RegExp(accountName) })).toBeVisible();
    await expect(dealsGroup.getByRole('option', { name: new RegExp(dealTitle) })).toBeVisible();

    // Navigare EXCLUSIV cu săgețile: primul rezultat (index 0, grupul „Accounts", primul
    // grup din răspuns) e deja activ la deschidere — o săgeată jos ajunge pe primul
    // rezultat din „Deals" (al doilea grup, un singur rezultat în „Accounts").
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL(new RegExp(dealUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$'));
    await expect(dialog).toBeHidden();

    // Redeschidere + Esc: închide și readuce focusul pe declanșator, fără să navigheze.
    await page.keyboard.press('ControlOrMeta+K');
    await expect(dialog).toBeVisible();
    // Focusul intră pe input printr-un `requestAnimationFrame` (`GlobalSearch.tsx`), NU
    // sincron cu deschiderea — fără să-l aștepte, `Escape`-ul de mai jos poate ajunge
    // înainte, pe orice avea focusul înainte de Cmd+K: `onInputKeyDown` (care readuce
    // focusul pe declanșator) nu s-ar mai declanșa deloc, doar `<dialog>`-ul nativ s-ar
    // închide pe calea „cancel" (`onClose={() => close(false)}`, focus NEschimbat).
    await expect(dialog.getByRole('combobox')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
    await expect(page).toHaveURL(new RegExp(dealUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$'));
});
