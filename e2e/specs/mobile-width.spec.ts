import { expect, test } from '@playwright/test';
import { authFile } from '../support/auth';

/**
 * Pe un ecran de telefon, nicio pagină nu derulează lateral.
 *
 * ## Ce s-a reparat aici
 *
 * Toate listele o făceau, câteva sute de pixeli, în GOL — o captură a zonei derulate arată
 * doar fundalul. Defect vechi, măsurat și pe commit-urile anterioare, nu adus de capetele
 * lipite.
 *
 * Cauza nu e cea care pare. Învelișul de tabel chiar decupa (`clientWidth` 341,
 * `scrollWidth` 894, derulare internă funcțională), dar documentul creștea odată cu tabelul.
 * Dovada că nu era o măsurătoare greșită: ascuns un SINGUR `<td>` dinăuntru,
 * `document.scrollWidth` scădea cu exact lățimea lui. Conținut dintr-un container cu
 * `overflow: auto` nu are voie să facă asta.
 *
 * `overflow-x: hidden` pe `<html>`, `<body>` sau `<main>` NU repară nimic (toate încercate,
 * toate confirmate aplicate, toate lăsând aceleași 523px de derulare). `contain: paint` pe
 * înveliș repară — vezi nota din `app.css`.
 *
 * ## De ce se așteaptă datele înainte de măsurătoare
 *
 * Prima versiune măsura imediat ce apărea `<h1>` — și trecea și cu repararea SCOASĂ, pe
 * toate cele 22 de rute. Motivul: listele își amână datele, deci în acel moment tabelul nu
 * exista încă, iar testul cântărea o pagină fără conținut lat. Un test verde care nu poate
 * pica e mai rău decât niciunul; de-aia se așteaptă dispariția scheletului, nu titlul.
 *
 * ## De ce testul e pe DERULARE, nu pe `scrollWidth`
 *
 * `scrollWidth` singur m-a trimis de două ori pe pistă greșită: sub emularea de telefon
 * raportează valori care nu corespund cu ce se întâmplă, iar `window.scrollX` rămâne 0 chiar
 * când pagina chiar derulează. Aserțiunea de aici e ce simte utilizatorul — „am încercat să
 * derulez lateral, și nu s-a mișcat nimic" — măsurată la lățime de telefon dar FĂRĂ emulare
 * de dispozitiv, singurul mod în care `scrollX` spune adevărul.
 */
test.use({ storageState: authFile('owner'), viewport: { width: 375, height: 760 } });

const ROUTES = [
    '/cascade/dashboard',
    '/cascade/accounts',
    '/cascade/contacts',
    '/cascade/deals',
    '/cascade/deals/board',
    '/cascade/products',
    '/cascade/orders',
    '/cascade/invoices',
    '/cascade/activity',
    '/cascade/pipeline',
    '/cascade/reports',
    '/cascade/imports',
    '/cascade/unassigned',
    '/cascade/settings',
    '/cascade/settings/members',
    '/cascade/settings/data-export',
    '/cascade/settings/api-tokens',
    '/cascade/settings/billing',
    '/cascade/settings/shipping',
    '/cascade/settings/webhooks',
    '/cascade/settings/sent-emails',
    '/cascade/settings/preferences',
] as const;

for (const route of ROUTES) {
    test(`${route} nu derulează lateral la 375px`, async ({ page }) => {
        await page.goto(route);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

        // Datele amânate TREBUIE să fi sosit — vezi docblock. Scheletul dispare când sosesc;
        // pe paginile fără prop-uri amânate nu apare niciodată, deci așteptarea e imediată.
        await expect(page.getByRole('status', { name: 'Loading' })).toHaveCount(0, { timeout: 15_000 });
        await page.waitForLoadState('networkidle');

        const scrolledTo = await page.evaluate(() => {
            window.scrollTo(900, 0);
            const x = window.scrollX;
            window.scrollTo(0, 0);

            return x;
        });

        expect(scrolledTo, 'pagina s-a mutat lateral — vezi docblock-ul').toBe(0);
    });
}

/**
 * Panoul „Needs attention" cu date lungi — DETERMINIST, spre deosebire de rutele de mai sus.
 *
 * Testul de pe `/cascade/dashboard` a picat o dată la șase rulări, cu documentul crescut cu
 * 4-29px, și a trecut la celelalte cinci: semănarea e aleatoare, deci numele de cont și
 * titlurile de afacere au altă lungime la fiecare rulare, iar defectul apărea doar când
 * nimereau destul de lungi. Un gate care depinde de zaruri nu e un gate — de-aia rândurile de
 * aici primesc un șir lung PRIN DOM, nu prin noroc.
 *
 * Ce prinde, exact: `min-width: auto` (implicitul oricărui element de grilă) face ca pista
 * `auto` să fie cel puțin cât min-content-ul conținutului, iar min-content-ul unui rând de
 * atenție e textul NETRUNCHIAT — `truncate` înseamnă `white-space: nowrap`, deci min-content-ul
 * lui e șirul întreg. `min-w-0` de pe `<span>`-ul dinăuntrul rândului NU ajută: `min-width` e
 * plafon de jos, nu de sus. Trebuie pe SECȚIUNE, care e elementul de grilă.
 *
 * Măsurat cu `min-w-0` scos: 670px de document pe un ecran de 375px. Cu el: 375px fix.
 */
test('panoul de atenție nu lățește pagina nici cu nume de cont absurd de lungi', async ({ page }) => {
    await page.goto('/cascade/dashboard');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByRole('status', { name: 'Loading' })).toHaveCount(0, { timeout: 15_000 });
    await page.waitForLoadState('networkidle');

    const randuriLungite = await page.evaluate(() => {
        const grid = document.querySelector('main .grid.gap-x-6.gap-y-5');
        if (grid === null) {
            return 0;
        }

        let n = 0;
        for (const detail of Array.from(grid.querySelectorAll('li span.min-w-0 > span'))) {
            detail.textContent = 'Granitehaven Hydraulic Components Group International Holdings Limited · 397 days late';
            n++;
        }

        return n;
    });

    // Dacă panoul e gol (seed fără facturi restante, fără stoc mic), testul n-a măsurat nimic
    // și trebuie să SPUNĂ asta — altfel ar fi încă un gate verde care nu poate pica.
    expect(randuriLungite, 'panoul de atenție n-avea niciun rând de lungit').toBeGreaterThan(0);

    const scrolledTo = await page.evaluate(() => {
        window.scrollTo(900, 0);
        const x = window.scrollX;
        window.scrollTo(0, 0);

        return x;
    });

    expect(scrolledTo, 'pagina s-a mutat lateral cu date lungi în panoul de atenție').toBe(0);
});

/**
 * Reversul: repararea de mai sus NU trebuie să fi omorât derularea orizontală DINĂUNTRUL
 * tabelului. Un tabel lat pe un ecran îngust trebuie să rămână accesibil prin glisare —
 * altfel „fără derulare laterală" s-ar fi obținut ascunzând coloane, ceea ce ar fi mai rău
 * decât problema.
 */
test('un tabel lat rămâne derulabil pe orizontală în interiorul lui', async ({ page }) => {
    await page.goto('/cascade/deals');
    await expect(page.getByRole('status', { name: 'Loading' })).toHaveCount(0, { timeout: 15_000 });

    const wrapper = page.locator('.data-table-scroll').first();
    await expect(wrapper).toBeVisible();

    const canScroll = await wrapper.evaluate((el) => el.scrollWidth > el.clientWidth + 1);

    expect(canScroll, 'tabelul lat nu mai poate fi derulat: coloanele au devenit inaccesibile').toBe(true);
});
