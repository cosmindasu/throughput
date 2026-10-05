import type { AppLocale } from '@/lib/i18n';

/**
 * Formatare locale-aware pentru date și numere — `FR-I18N-03` (specs.md §15.8), Val 3 din
 * „Lot I18N" (plan-implementare.md, între Fazele 5 și 6, ADR-022).
 *
 * **De ce există fișierul ăsta.** Până la Val 3, fiecare ecran își instanția propriul
 * formator LA NIVEL DE MODUL, cu locale-ul fixat în closure:
 *
 * ```ts
 * const dateTimeFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' });
 * ```
 *
 * Erau 20 de astfel de formatoare, în 17 fișiere. Un formator creat la `import` nu se
 * poate „repara" schimbând o variabilă la runtime: obiectul `Intl` își închide locale-ul
 * la construcție. Comutatorul de limbă (`Components/LocaleToggle.tsx`) navighează cu
 * `preserveState: true`, deci modulul NU se reîncarcă — un utilizator care trece pe FR ar
 * fi continuat să vadă date engleze până la un refresh complet.
 *
 * **Cache cheiat pe `(locale, opțiuni)`, nu doar pe opțiuni.** Aceeași clasă de bug ca
 * discrepanța de temă P2-004 (Faza 2) și ca cea reparată în `lib/money.ts`: un cache
 * global fără locale în cheie întoarce primul formator cerut, tăcut. Cheia include
 * locale-ul PRIMUL, ca două limbi să nu se poată suprascrie reciproc.
 *
 * **Fabrica generică (`getDateTimeFormat`) e deliberat expusă, nu doar ajutoarele
 * numite.** Suprafața are șapte forme distincte de dată, iar două dintre ele diferă în
 * engleză DOAR prin `hour: 'numeric'` vs `hour: '2-digit'` („5:09 PM" vs „05:09 PM") —
 * verificat, nu presupus. Suita E2E are 297 de selectori pe text vizibil, fixați pe `en`;
 * a colapsa două forme care arată la fel în franceză, dar diferit în engleză, ar fi rupt
 * exact selectorii pe care lotul nu are voie să-i atingă. Un ecran cu o formă proprie își
 * păstrează obiectul de opțiuni ca `const` de modul (date pure, fără locale) și îl trece
 * fabricii — identitatea în engleză e garantată prin construcție.
 *
 * Mutația `Map` de mai jos e într-o funcție de MODUL, nu în corpul unui component —
 * aceeași formă pe care `react-hooks/immutability` o acceptă deja în `lib/money.ts` și pe
 * care `.ai/rules/frontend.md` o cere pentru `applyDocumentLocale`/`applyResolvedTheme`.
 */
const dateTimeFormatters = new Map<string, Intl.DateTimeFormat>();
const numberFormatters = new Map<string, Intl.NumberFormat>();

export function getDateTimeFormat(locale: AppLocale, options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
    const key = `${locale}:${JSON.stringify(options)}`;
    let formatter = dateTimeFormatters.get(key);

    if (!formatter) {
        formatter = new Intl.DateTimeFormat(locale, options);
        dateTimeFormatters.set(key, formatter);
    }

    return formatter;
}

export function getNumberFormat(locale: AppLocale, options: Intl.NumberFormatOptions = {}): Intl.NumberFormat {
    const key = `${locale}:${JSON.stringify(options)}`;
    let formatter = numberFormatters.get(key);

    if (!formatter) {
        formatter = new Intl.NumberFormat(locale, options);
        numberFormatters.set(key, formatter);
    }

    return formatter;
}

/** Formele de dată folosite de mai mult de un ecran. Date pure — fără locale în ele. */
export const DATE_MEDIUM: Intl.DateTimeFormatOptions = { dateStyle: 'medium' };
export const DATE_TIME_MEDIUM: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: 'short' };

/** „Mar 14, 2026" / „14 mars 2026". */
export function formatDate(value: string | number | Date, locale: AppLocale): string {
    return getDateTimeFormat(locale, DATE_MEDIUM).format(new Date(value));
}

/** „Mar 14, 2026, 5:09 PM" / „14 mars 2026, 17:09". */
/**
 * O dată CALENDARISTICĂ („2026-10-12"), nu un moment. Diferența contează: `new Date('2026-10-12')`
 * e miezul nopții UTC, iar `Intl` îl formatează în fusul local — la vest de Greenwich iese ziua
 * precedentă. Măsurat: `America/New_York` afișa „Oct 11, 2026" pentru 12 octombrie.
 *
 * Coloanele `date` din Postgres (`expected_close_date`, `due_date`, `issue_date`) ajung în JSON
 * exact în forma asta, deci ORICE afișare a lor trece pe aici, nu pe `formatDate`.
 */
export function formatCalendarDate(isoDate: string, locale: AppLocale): string {
    const [year, month, day] = isoDate.split('-').map(Number);

    return getDateTimeFormat(locale, DATE_MEDIUM).format(new Date(year, month - 1, day));
}

export function formatDateTime(value: string | number | Date, locale: AppLocale): string {
    return getDateTimeFormat(locale, DATE_TIME_MEDIUM).format(new Date(value));
}

/**
 * Forma pur numerică a lui `Date.toLocaleDateString()` fără opțiuni — „3/14/2026" în
 * engleză, „14/03/2026" în franceză. Păstrată ca funcție separată, nu unificată cu
 * `formatDate`: cele două produc text DIFERIT în ambele limbi, iar ecranele care o
 * foloseau azi (`Accounts/Index`, `Settings/Billing/Index`) rămân la aceeași formă.
 */
export function formatDateNumeric(value: string | number | Date, locale: AppLocale): string {
    return getDateTimeFormat(locale, {}).format(new Date(value));
}

/** „1,234,567" / „1 234 567" (spațiu insecabil îngust în franceză — FR-I18N-03). */
export function formatNumber(value: number, locale: AppLocale): string {
    return getNumberFormat(locale).format(value);
}

/** Două zecimale fixe — „1,234.50" / „1 234,50". */
export function formatDecimal(value: number, locale: AppLocale): string {
    return getNumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
}
