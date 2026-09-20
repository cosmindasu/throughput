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
 */
export function formatMoney(value: number | null, currency: string, options: FormatMoneyOptions = {}): string {
    if (value === null) {
        return '—';
    }

    const key = `${currency}:${options.maximumFractionDigits ?? 'default'}`;
    let formatter = formatters.get(key);

    if (!formatter) {
        formatter = new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
            ...(options.maximumFractionDigits === undefined
                ? {}
                : { maximumFractionDigits: options.maximumFractionDigits }),
        });
        formatters.set(key, formatter);
    }

    return formatter.format(value);
}
