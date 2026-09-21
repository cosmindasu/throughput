import { Head, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Trans, useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import EmptyState from '@/Components/EmptyState';
import { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import AppLayout from '@/Layouts/AppLayout';
import { formatNumber, getDateTimeFormat } from '@/lib/format';
import type { AppLocale } from '@/lib/i18n';
import type { WebhookEventStatus, WebhookHealthPageProps } from '@/types/generated';

/**
 * `ignored` și `failed` NU împart nicio tentă: `failed` e singurul roșu (alerta din
 * specs.md §25.2 se uită exact la el), `ignored` e neutru/mut — un eveniment al altui
 * proiect din sandbox-ul Stripe comun nu e o defecțiune a noastră. Asta e tot rostul
 * statusului nou: un ecran de operare plin de roșu străin face roșul inutil.
 */
const STATUS_TONE: Record<WebhookEventStatus, BadgeTone> = {
    received: 'info',
    processing: 'accent',
    processed: 'success',
    failed: 'danger',
    ignored: 'neutral',
};

function statusLabel(status: WebhookEventStatus, t: TFunction<'settings'>): string {
    return t(`settings:webhooks.status.${status}`);
}

/**
 * `.ai/rules/frontend.md` / brief Val 3 — `OPTIONS` păstrat ca `const` de modul, cu
 * `hour: 'numeric'` EXACT cum era (nu `2-digit`).
 */
const WHEN_OPTIONS: Intl.DateTimeFormatOptions = {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
};

function formatWhen(value: string | null, locale: AppLocale): string {
    if (value === null) {
        return '—';
    }

    return getDateTimeFormat(locale, WHEN_OPTIONS).format(new Date(value));
}

function CountTile({ status, total, t, locale }: { status: WebhookEventStatus; total: number; t: TFunction<'settings'>; locale: AppLocale }) {
    return (
        <div className="flex flex-col gap-1 rounded-lg border border-border bg-surface px-4 py-3">
            <StatusBadge tone={STATUS_TONE[status]}>{statusLabel(status, t)}</StatusBadge>
            <span className="numeric text-2xl font-semibold text-text">{formatNumber(total, locale)}</span>
        </div>
    );
}

/**
 * Settings → Webhook health (specs.md §25.2, criteriul de acceptanță din §12.3).
 *
 * Ecranul NU arată niciodată `payload`-ul unui eveniment: `webhook_events` e o tabelă de
 * DEPLOYMENT, fără `tenant_id` și fără RLS (§19.1), deci singurul conținut sigur de afișat
 * într-un workspace e metadata + mesajul scris de noi. Vezi
 * `App\Http\Controllers\Webhooks\WebhookHealthController`.
 */
export default function WebhookHealthIndex() {
    const page = usePage<WebhookHealthPageProps>();
    const { events, counts, statuses, filter } = page.props;
    const { t } = useTranslation('settings');
    const locale = useLocale();

    const setStatus = (status: string) => {
        router.get(page.url.split('?')[0], status === '' ? {} : { status }, {
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <>
            <Head title={t('settings:webhooks.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader title={t('settings:webhooks.title')} description={t('settings:webhooks.description')} />

                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    {statuses.map((status) => (
                        <CountTile key={status} status={status} total={counts[status] ?? 0} t={t} locale={locale} />
                    ))}
                </div>

                <p className="max-w-3xl text-sm text-text-2">
                    <Trans
                        t={t}
                        i18nKey="settings:webhooks.ignoredExplanation"
                        values={{ failedLabel: t('settings:webhooks.status.failed') }}
                        components={{
                            strong: <strong className="font-medium text-text" />,
                            danger: <span className="font-medium text-danger" />,
                        }}
                    />
                </p>

                <label className="flex w-fit flex-col gap-1 text-sm text-text-2">
                    {t('settings:webhooks.statusFilterLabel')}
                    <select className={controlClass} value={filter.status ?? ''} onChange={(event) => setStatus(event.target.value)}>
                        <option value="">{t('settings:webhooks.anyStatus')}</option>
                        {statuses.map((status) => (
                            <option key={status} value={status}>
                                {statusLabel(status, t)}
                            </option>
                        ))}
                    </select>
                </label>

                {events.length === 0 ? (
                    <EmptyState message={t('settings:webhooks.empty')} />
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-border bg-surface">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">{t('settings:webhooks.tableCaption')}</caption>
                            <thead>
                                <tr className="border-b border-border-soft text-xs text-text-3">
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        {t('settings:webhooks.columns.received')}
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        {t('settings:webhooks.columns.source')}
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        {t('settings:webhooks.columns.event')}
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        {t('settings:webhooks.columns.status')}
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        {t('settings:webhooks.columns.detail')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {events.map((event) => (
                                    <tr key={event.id} className="border-b border-border-soft last:border-b-0">
                                        <td className="whitespace-nowrap px-4 py-2 text-text-2">
                                            <time dateTime={event.receivedAt ?? undefined} className="numeric">
                                                {formatWhen(event.receivedAt, locale)}
                                            </time>
                                        </td>
                                        <td className="px-4 py-2 text-text-2">{event.source}</td>
                                        <td className="px-4 py-2">
                                            <div className="font-medium text-text">{event.type}</div>
                                            <div className="numeric text-xs text-text-3">{event.eventId}</div>
                                        </td>
                                        <td className="px-4 py-2">
                                            <StatusBadge tone={STATUS_TONE[event.status]}>{statusLabel(event.status, t)}</StatusBadge>
                                        </td>
                                        <td className="px-4 py-2 text-text-2">{event.message ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

WebhookHealthIndex.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
