import { Head, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import EmptyState from '@/Components/EmptyState';
import { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
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

const STATUS_LABEL: Record<WebhookEventStatus, string> = {
    received: 'Received',
    processing: 'Processing',
    processed: 'Processed',
    failed: 'Failed',
    ignored: 'Ignored (not ours)',
};

function formatWhen(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function CountTile({ status, total }: { status: WebhookEventStatus; total: number }) {
    return (
        <div className="flex flex-col gap-1 rounded-lg border border-border bg-surface px-4 py-3">
            <StatusBadge tone={STATUS_TONE[status]}>{STATUS_LABEL[status]}</StatusBadge>
            <span className="numeric text-2xl font-semibold text-text">{total}</span>
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

    const setStatus = (status: string) => {
        router.get(page.url.split('?')[0], status === '' ? {} : { status }, {
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <>
            <Head title="Webhook health" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Webhook health"
                    description="Every Stripe event this deployment has received, with what happened to it (specs.md §12.3, §25.2)."
                />

                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    {statuses.map((status) => (
                        <CountTile key={status} status={status} total={counts[status] ?? 0} />
                    ))}
                </div>

                <p className="max-w-3xl text-sm text-text-2">
                    <strong className="font-medium text-text">Ignored is not an error.</strong> The Stripe sandbox is
                    shared with another project, so events belonging to that project reach this endpoint with a perfectly
                    valid signature. They are recorded and left alone — no workspace here maps to their customer. Only{' '}
                    <span className="font-medium text-danger">Failed</span> needs attention.
                </p>

                <label className="flex w-fit flex-col gap-1 text-sm text-text-2">
                    Status
                    <select className={controlClass} value={filter.status ?? ''} onChange={(event) => setStatus(event.target.value)}>
                        <option value="">Any status</option>
                        {statuses.map((status) => (
                            <option key={status} value={status}>
                                {STATUS_LABEL[status]}
                            </option>
                        ))}
                    </select>
                </label>

                {events.length === 0 ? (
                    <EmptyState message="No webhook events have been received yet." />
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-border bg-surface">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">Webhook events</caption>
                            <thead>
                                <tr className="border-b border-border-soft text-xs text-text-3">
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        Received
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        Source
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        Event
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        Detail
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {events.map((event) => (
                                    <tr key={event.id} className="border-b border-border-soft last:border-b-0">
                                        <td className="whitespace-nowrap px-4 py-2 text-text-2">
                                            <time dateTime={event.receivedAt ?? undefined} className="numeric">
                                                {formatWhen(event.receivedAt)}
                                            </time>
                                        </td>
                                        <td className="px-4 py-2 text-text-2">{event.source}</td>
                                        <td className="px-4 py-2">
                                            <div className="font-medium text-text">{event.type}</div>
                                            <div className="numeric text-xs text-text-3">{event.eventId}</div>
                                        </td>
                                        <td className="px-4 py-2">
                                            <StatusBadge tone={STATUS_TONE[event.status]}>{STATUS_LABEL[event.status]}</StatusBadge>
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
