import type { TFunction } from 'i18next';

/**
 * Eticheta afișabilă a unui rol RBAC (§7.4) — Valul 3 al Lotului I18N, decizie a
 * proprietarului (2026-09-21) de a traduce numele rolurilor.
 *
 * **Identificatorul NU se traduce niciodată.** Ce circulă prin props, prin `PATCH`-ul de
 * schimbare de rol și prin Spatie Permission rămâne constanta din `App\Support\Permissions`
 * (`'Owner'`, `'Manager'`, …). Funcția asta traduce DOAR ce vede utilizatorul; valoarea
 * trimisă înapoi la server e mereu cea originală. De-aia `<option>`-urile din
 * `ChangeRoleDialog`/`InviteMemberDialog` păstrează `value={role}` și schimbă doar textul.
 *
 * Normalizează cu minuscule fiindcă identificatorul ajunge aici în DOUĂ forme, ambele
 * legitime: TitleCase din `Permissions` (`member.role`, `assignableRoles`) și minuscule din
 * slug-ul de rută al login-ului demo (`/login/demo/owner`, prop `account.role`). O a doua
 * hartă de conversie între ele ar fi fost un loc în plus care se poate desincroniza.
 *
 * Un rol necunoscut se întoarce NESCHIMBAT, nu ca o cheie brută („roles:supervisor") și nu
 * ca text gol: dacă cineva adaugă un al cincilea rol în `Permissions` și uită catalogul,
 * ecranul arată identificatorul englezesc — degradare lizibilă, nu un ecran stricat. Gate-ul
 * `php artisan i18n:coverage` nu poate prinde cazul ăsta (compară `en` cu `fr`, nu catalogul
 * cu `Permissions`), deci fallback-ul e singura plasă.
 */
const KNOWN_ROLES = ['owner', 'manager', 'agent', 'viewer'] as const;

export function roleLabel(t: TFunction, role: string | null | undefined): string | null {
    if (!role) {
        return null;
    }

    const key = role.toLowerCase();

    return (KNOWN_ROLES as readonly string[]).includes(key) ? t(`roles:${key}`) : role;
}
