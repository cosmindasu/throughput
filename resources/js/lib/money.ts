const formatters = new Map<string, Intl.NumberFormat>();

/**
 * `deals.value` e nullabil (§9.2 — „nullabil până la calificare"); un formator per
 * monedă e memorat, nu recreat la fiecare rând (`Deals/Index` poate arăta 50+ rânduri
 * pe ecran).
 */
export function formatMoney(value: number | null, currency: string): string {
    if (value === null) {
        return '—';
    }

    let formatter = formatters.get(currency);

    if (!formatter) {
        formatter = new Intl.NumberFormat('en-US', { style: 'currency', currency });
        formatters.set(currency, formatter);
    }

    return formatter.format(value);
}
