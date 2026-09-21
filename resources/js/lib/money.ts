import type { AppLocale } from '@/lib/i18n';

const formatters = new Map<string, Intl.NumberFormat>();

interface FormatMoneyOptions {
    /** KPI-urile de dashboard afișează sume rotunjite la unitate; listele afișează cenți. */
    maximumFractionDigits?: number;
}

/**
 * `deals.value` e nullabil (§9.2 — „nullabil până la calificare"); un formator per
 * monedă e memorat, nu recreat la fiecare rând (`Deals/Index` poate arăta 50+ rânduri
 * pe ecran).
 *
 * Moneda vine ÎNTOTDEAUNA ca parametru, niciodată fixată în cod: specs.md §2.3 spune „o
 * singură monedă per tenant (implicit USD), configurabilă" — deci `tenants.currency`, nu
 * o constantă. Dashboard-ul o fixase la `'USD'` într-un formator propriu, ocolind această
 * funcție; pe orice tenant care nu e pe USD, cele două KPI-uri de bani afișau simbolul
 * greșit. Găsit la un audit încrucișat, în Faza 5.
 *
 * Cheia de cache include opțiunile, nu doar moneda: două formatoare pentru aceeași monedă
 * cu precizii diferite sunt obiecte diferite, iar o cheie doar pe monedă ar întoarce
 * primul formator cerut, tăcut.
 *
 * **Locale-ul e PRIMUL în cheie (Val 3, „Lot I18N", FR-I18N-03).** Până aici cheia era
 * `(monedă, precizie)`, cu `'en-US'` fixat în construcție: primul formator cerut pentru
 * USD rămânea în `Map` cu regulile engleze, iar un utilizator care comuta pe franceză îl
 * primea înapoi tăcut — comutatorul navighează cu `preserveState: true`, deci modulul nu
 * se reîncarcă și cache-ul nu se golește. Aceeași clasă de bug ca discrepanța de temă
 * P2-004 din Faza 2: cache global fără scop per-utilizator.
 *
 * **`currencyDisplay: 'narrowSymbol'`, nu implicitul.** Implicitul ICU randează USD în
 * franceză ca `1 234,56 $US` (dezambiguizare față de dolarul canadian), iar FR-I18N-03
 * cere explicit forma `1 234,56 $`. Verificat că `narrowSymbol` NU schimbă nimic pe
 * engleză (`$1,234.56` în ambele variante), deci niciunul dintre cei 297 de selectori pe
 * text ai suitei E2E fixate pe `en` nu se mișcă.
 */
export function formatMoney(
    value: number | null,
    currency: string,
    locale: AppLocale,
    options: FormatMoneyOptions = {}
): string {
    if (value === null) {
        return '—';
    }

    const key = `${locale}:${currency}:${options.maximumFractionDigits ?? 'default'}`;
    let formatter = formatters.get(key);

    if (!formatter) {
        formatter = new Intl.NumberFormat(locale, {
            style: 'currency',
            currency,
            currencyDisplay: 'narrowSymbol',
            ...(options.maximumFractionDigits === undefined
                ? {}
                : { maximumFractionDigits: options.maximumFractionDigits }),
        });
        formatters.set(key, formatter);
    }

    return formatter.format(value);
}
