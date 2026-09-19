import { expect, test } from '@playwright/test';
import { xsrfHeader } from '../support/api';
import { authFile } from '../support/auth';
import { inertiaPageProps } from '../support/inertia';

/**
 * Faza 3, valul 2 (specs.md §6.4.1, US-TEN-03) — dezactivarea unui membru.
 *
 * **Constatare majoră, raportată aici și în raportul agentului, nu ascunsă**: fluxul
 * COMPLET din Gherkin-ul US-TEN-03 (dialogul cu numerele exacte, „Deactivate anyway",
 * vederea „Unassigned", indicatorul din navigație, reatribuirea, „(deactivated)" pe
 * istoric, blocarea ultimului Owner) **nu se poate testa în acest mediu**:
 * `App\Support\DemoMode::GUARDED_ACTIONS['members.deactivate']` (adăugat chiar în acest
 * val, `c827853`) dezactivează acțiunea necondiționat, pentru ORICE rol, inclusiv Owner,
 * cât timp `DEMO_MODE=true` — motivul e explicit în cod: conturile demo (§4.2) sunt
 * LOGIN-URI PARTAJATE, iar dezactivarea unuia ar rupe autentificarea tuturor vizitatorilor
 * următori. `DEMO_MODE=true` e însă OBLIGATORIU pentru suita asta
 * (`e2e/support/env.ts` — altfel `/login/demo/{role}` răspunde 404 și `auth.setup.ts` n-are
 * ce apăsa), deci nu există o cale, cu tiparul de autentificare al suitei, să dezactivăm
 * DEMO_MODE doar pentru acest fișier fără să rupem autentificarea restului suitei.
 *
 * Ce testează fișierul ăsta în loc: cele DOUĂ straturi ale guardrail-ului însuși
 * (`EnsureDemoModeGuardrails`, „interfața nu randează butonul, cererea care ajunge totuși
 * e refuzată") — un rezultat real, verificabil în browser, chiar dacă nu e US-TEN-03.
 * Restul fluxului (numerele din dialog, Unassigned, reatribuirea, placeholder-ul
 * „(deactivated)") rămâne acoperit de suita Pest a pachetului:
 * `tests/Feature/Members/MemberDeactivationTest.php`,
 * `tests/Feature/Members/UnassignedViewTest.php`,
 * `tests/Feature/Members/BulkOperationGroupTest.php`,
 * `tests/Feature/Members/DeactivatedMemberPlaceholderTest.php`,
 * `tests/Feature/Members/MembersDemoModeGuardrailTest.php` — teste de server, care rulează
 * fără `DEMO_MODE` fixat pe `true` de o constrângere de infrastructură a suitei E2E.
 */
test.use({ storageState: authFile('owner') });

const BASE = '/marlin';
const MEMBERS_URL = `${BASE}/settings/members`;

const REFUSAL_MESSAGE = 'Deactivating a member is disabled in the public demo — these are the shared logins other visitors use.';

interface MembersPageProps {
    members: Array<{
        id: string;
        user: { id: string; name: string; email: string };
        status: 'active' | 'pending' | 'deactivated';
    }>;
    auth: { user: { email: string } };
}

test('stratul de interfață — „Deactivate member" e absent din DOM cât DEMO_MODE=true, indiferent de rol (Owner inclus)', { tag: ['@smoke'] }, async ({
    page,
}) => {
    await page.goto(MEMBERS_URL);
    await page.getByRole('table').waitFor();

    // Sanity — testul „zero butoane" n-ar dovedi nimic pe un tabel gol sau cu un singur
    // rând (Owner-ul curent, care oricum nu se poate dezactiva pe sine).
    const rowCount = await page.locator('table tbody tr').count();
    expect(rowCount, 'demo-ul seamănă mai mulți membri per tenant — un singur rând ar face testul trivial').toBeGreaterThan(1);

    // BR-DEMO-01 — oprită „indiferent de rol", deci absentă și pe randul Owner-ului curent:
    // FR-RBAC-01, un buton care ar da 403 la click nu se randează niciodată, doar ascuns.
    await expect(page.getByRole('button', { name: /^Deactivate/ })).toHaveCount(0);
});

test('stratul de server — un POST direct către ruta gardată e refuzat, cu mesajul exact al guardrail-ului', async ({ page }) => {
    const props = await inertiaPageProps<MembersPageProps>(page, MEMBERS_URL);

    const target = props.members.find(
        (member) => member.status === 'active' && member.user.email !== props.auth.user.email,
    );
    expect(target, 'are nevoie de cel puțin un membru activ, diferit de Owner-ul autentificat').toBeTruthy();

    // Cererea REALĂ pe care ar fi trimis-o `router.post` din `Settings/Members/Index.tsx`
    // (`X-Inertia: true`) — un buton care n-a apucat să dispară din DOM la timp, sau un
    // client vechi care mai ține pagina în cache. `EnsureDemoModeGuardrails` refuză DUPĂ
    // numele rutei, nu după conținutul cererii — id-ul membrului nici nu contează aici,
    // dar folosim unul real, ca testul să rămână valabil chiar dacă guardrail-ul ar fi
    // vreodată ocolit.
    const response = await page.request.post(`${MEMBERS_URL}/${target!.id}/deactivate`, {
        headers: {
            ...(await xsrfHeader(page)),
            'X-Inertia': 'true',
            Referer: MEMBERS_URL,
        },
        data: { reassign: false },
        maxRedirects: 0,
        failOnStatusCode: false,
    });

    // `back()->with('error', ...)` pe o cerere Inertia — un 302, NU un 403 opac (docblock-ul
    // `EnsureDemoModeGuardrails`: „un 403 afișat într-un modal de eroare ar arăta ca o
    // aplicație stricată").
    expect(response.status()).toBe(302);

    // Flash-ul de sesiune supraviețuiește exact o cerere — următoarea navigare normală
    // (nu un `page.request` separat) îl citește prin `flash.error` (`HandleInertiaRequests`)
    // și `FlashMessages.tsx` îl randează ca `role="alert"`.
    await page.goto(MEMBERS_URL);
    await expect(page.getByRole('alert')).toHaveText(REFUSAL_MESSAGE);

    // Dovada că refuzul chiar a oprit acțiunea, nu doar a afișat un mesaj cosmetic peste o
    // dezactivare care s-a întâmplat oricum.
    const after = await inertiaPageProps<MembersPageProps>(page, MEMBERS_URL);
    const afterTarget = after.members.find((member) => member.id === target!.id);
    expect(afterTarget?.status).toBe('active');
});
