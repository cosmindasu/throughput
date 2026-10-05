import type { AppLocale } from '@/lib/i18n';

const formatters = new Map<AppLocale, Intl.RelativeTimeFormat>();

/** Pentru termene calendaristice (fără oră): `days` vine deja calculat, calendaristic, de apelant. */
export function relativeDays(days: number, locale: AppLocale): string {
    let formatter = formatters.get(locale);

    if (!formatter) {
        formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
        formatters.set(locale, formatter);
    }

    return formatter.format(days, 'day');
}
