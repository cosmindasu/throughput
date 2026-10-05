import type { AppLocale } from '@/lib/i18n';

const formatters = new Map<AppLocale, Intl.RelativeTimeFormat>();

/**
 * „acum 2 h" / „ieri" / „3 zile în urmă" prin `Intl.RelativeTimeFormat` — pluralizarea și
 * limba vin gratis din motorul CLDR (aceeași motivație ca în `lib/format.ts`). Peste 7 zile
 * întoarce `null`, iar apelantul arată data absolută.
 */
export function relativeTime(iso: string, now: number, locale: AppLocale): string | null {
    let formatter = formatters.get(locale);

    if (!formatter) {
        formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
        formatters.set(locale, formatter);
    }

    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
    const abs = Math.abs(seconds);

    if (abs < 60) {
        return formatter.format(0, 'second');
    }
    if (abs < 3600) {
        return formatter.format(Math.round(seconds / 60), 'minute');
    }
    if (abs < 86_400) {
        return formatter.format(Math.round(seconds / 3600), 'hour');
    }
    if (abs < 7 * 86_400) {
        return formatter.format(Math.round(seconds / 86_400), 'day');
    }

    return null;
}

/** Pentru termene calendaristice (fără oră): `days` vine deja calculat, calendaristic, de apelant. */
export function relativeDays(days: number, locale: AppLocale): string {
    let formatter = formatters.get(locale);

    if (!formatter) {
        formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
        formatters.set(locale, formatter);
    }

    return formatter.format(days, 'day');
}
