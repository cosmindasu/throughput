import { expect, type Page } from '@playwright/test';

/**
 * Cardul de progres al unei operații în masă (`Bulk/Show.tsx`, `role="status"`).
 *
 * De ce nu `getByRole('status')` simplu și nici `div[aria-live="polite"]`: de la valul 2 al
 * Fazei 5, `AppLayout` montează DOUĂ regiuni live PERMANENTE pe ORICE pagină —
 * `FlashMessages.tsx` (`role="status"` + `role="alert"`, goale cât n-au mesaj) și
 * `ListUpdateAnnouncer.tsx` (`role="status"`, `sr-only`, goală). Sunt născute goale
 * DELIBERAT (SC 4.1.3: un cititor de ecran urmărește mutațiile unei regiuni pe care a
 * înregistrat-o deja, nu apariția regiunii), deci orice locator „prima regiune live de pe
 * pagină" e de-acum ambiguu în sens Playwright strict.
 *
 * Ancora aleasă e CONȚINUTUL invariant al cardului — „{procesate} / {total} processed",
 * randat în orice stare, inclusiv terminală — nu o clasă CSS și nici un `nth()`.
 */
export function bulkStatusRegion(page: Page) {
    return page.getByRole('status').filter({ hasText: /processed/ });
}

/**
 * Bifează o casetă de selecție dintr-o listă apăsând ETICHETA ei, nu `input`-ul.
 *
 * Constatare a valului 3, la integrarea lotului de accesibilitate: casetele din liste sunt
 * acum înfășurate într-un `<label class="… before:absolute before:-inset_1 before:content-['']">`
 * — o zonă de atingere lărgită pentru SC 2.5.8 (Target Size). Pseudo-elementul acoperă
 * `input`-ul, deci `locator.check()` pe `input` nu se termină NICIODATĂ: verificarea de
 * acționabilitate a lui Playwright vede eticheta interceptând evenimentele de pointer și
 * reîncearcă până la timeout (măsurat: 325 de reîncercări în 3 minute).
 *
 * NU e un defect de produs — un om care apasă tot acolo activează eticheta, iar eticheta
 * comută caseta; și `force: true` ar fi greșit, fiindcă ar ascunde și interceptările REALE
 * (un overlay care chiar acoperă controlul). Apăsăm exact ce apasă utilizatorul: eticheta.
 * Starea se verifică pe `input`, deci efectul rămâne asertat, nu presupus.
 */
export async function checkByLabel(page: Page, accessibleName: string): Promise<void> {
    const checkbox = page.getByRole('checkbox', { name: accessibleName });

    await checkbox.locator('xpath=ancestor::label[1]').click();
    await expect(checkbox).toBeChecked();
}
