import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Icon, { type IconName } from '@/Components/Icon';
import ToneIcon from '@/Components/ToneIcon';
import { useLocale } from '@/hooks/useLocale';
import { formatMoney } from '@/lib/money';
import { relativeDays } from '@/lib/relativeTime';
import type { Tone } from '@/lib/tone';
import type { DashboardAttention } from '@/types/generated';

interface Row {
    key: string;
    title: string;
    detail: string;
    trailing: string;
    href: string | null;
}

interface Group {
    key: string;
    heading: string;
    icon: IconName;
    tone: Tone;
    rows: Row[];
}

/**
 * „Ce cere acțiune acum": KPI-urile de deasupra spun CÂTE, lista asta spune CARE — cu nume,
 * contextul minim pentru a prioritiza (clientul, termenul, cantitatea) și un link către
 * înregistrare. Fără ea, cele patru cifre din plăci nu duc nicăieri: afli că ai nouă facturi
 * restante și tot trebuie să deschizi un alt ecran ca să afli pe care.
 *
 * Grupat pe cele trei feluri de urgență, nu amestecat într-o singură listă ordonată: o
 * factură restantă, o afacere care se închide săptămâna viitoare și un SKU pe terminate nu
 * sunt comparabile între ele, deci n-au o ordine comună care să însemne ceva. Antetul
 * fiecărui grup e cel care spune DE CE e rândul acolo — iconul și tenta doar îl dublează,
 * deci nicio informație nu depinde de culoare (SC 1.4.1).
 */
export default function AttentionList({ data, currency }: { data: DashboardAttention; currency: string }) {
    const { t } = useTranslation('dashboard');
    const locale = useLocale();
    const money = (value: number) => formatMoney(value, currency, locale, { maximumFractionDigits: 0 });
    // Miezul de noapte LOCAL, nu `Date.now()`: diferența până la o dată calendaristică se
    // numără în zile de calendar, iar scăderea din ora curentă ar da „în 0 zile" pentru mâine
    // dimineață și „în 1 zi" pentru aceeași dată citită seara.
    const today = new Date().setHours(0, 0, 0, 0);

    const groups: Group[] = [
        {
            key: 'overdueInvoices',
            heading: t('dashboard:attention.overdueInvoices'),
            icon: 'clock',
            tone: 'danger',
            rows: data.overdueInvoices.map((invoice) => ({
                key: invoice.id,
                title: invoice.label,
                detail: t('dashboard:attention.overdueBy', { count: invoice.daysOverdue, account: invoice.account ?? '—' }),
                trailing: money(invoice.amount),
                href: invoice.url,
            })),
        },
        {
            key: 'closingSoon',
            heading: t('dashboard:attention.closingSoon'),
            icon: 'calendar',
            tone: 'warning',
            rows: data.closingSoon.map((deal) => ({
                key: deal.id,
                title: deal.label,
                detail: deal.account ?? '—',
                trailing:
                    deal.closesOn === null
                        ? '—'
                        : relativeDays(Math.round((new Date(`${deal.closesOn}T00:00:00`).getTime() - today) / 86_400_000), locale),
                href: deal.url,
            })),
        },
        {
            key: 'lowStock',
            heading: t('dashboard:attention.lowStock'),
            icon: 'alert',
            tone: 'warning',
            rows: data.lowStock.map((level) => ({
                key: level.id,
                title: level.label,
                detail: level.product ?? '—',
                // Disponibilul SINGUR nu spune cât de rău e: 3 bucăți e o criză pentru un
                // articol cu prag 20 și o zi obișnuită pentru unul cu prag 4. Pragul e chiar
                // ce face rândul să fie acolo, deci apare lângă cifră.
                trailing: t('dashboard:attention.available', { count: level.available, threshold: level.threshold }),
                href: level.url,
            })),
        },
    ];

    if (groups.every((group) => group.rows.length === 0)) {
        return (
            <p className="flex items-center gap-2 text-sm text-text-2">
                <Icon name="check" size={16} className="text-success" />
                {t('dashboard:attention.allClear')}
            </p>
        );
    }

    return (
        <div className="grid gap-x-6 gap-y-5 sm:grid-cols-3">
            {groups.map((group) => (
                <section key={group.key} aria-label={group.heading}>
                    <h3 className="flex items-center gap-2 text-xs font-medium tracking-wide text-text-2 uppercase">
                        <ToneIcon tone={group.tone} name={group.icon} size="sm" />
                        {group.heading}
                    </h3>
                    {group.rows.length === 0 ? (
                        <p className="mt-2 text-sm text-text-3">{t('dashboard:attention.none')}</p>
                    ) : (
                        <ul className="mt-1 divide-y divide-border-soft">
                            {group.rows.map((row) => (
                                <li key={row.key} className="flex items-baseline justify-between gap-3 py-2 text-sm">
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium text-text">
                                            {row.href === null ? (
                                                row.title
                                            ) : (
                                                <Link href={row.href} className="hover:text-accent-text hover:underline">
                                                    {row.title}
                                                </Link>
                                            )}
                                        </span>
                                        <span className="block truncate text-xs text-text-3">{row.detail}</span>
                                    </span>
                                    <span className="numeric shrink-0 text-xs text-text-2">{row.trailing}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            ))}
        </div>
    );
}
