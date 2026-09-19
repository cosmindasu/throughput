import { Deferred, Head, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import CursorPagination from '@/Components/CursorPagination';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useListFilters } from '@/hooks/useListFilters';
import AppLayout from '@/Layouts/AppLayout';
import type { SentEmailRow, SentEmailStatus, SentEmailsIndexPageProps } from '@/types/generated';

function statusTone(status: SentEmailStatus): BadgeTone {
    return {
        delivered: 'success' as const,
        intercepted: 'warning' as const,
        partial: 'accent' as const,
        failed: 'danger' as const,
    }[status];
}

function statusLabel(status: SentEmailStatus): string {
    return {
        delivered: 'Delivered',
        intercepted: 'Intercepted',
        partial: 'Partially delivered',
        failed: 'Failed',
    }[status];
}

function formatWhen(value: string): string {
    return new Date(value).toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function recipientSummary(email: SentEmailRow): string {
    const to = email.recipients.filter((recipient) => recipient.type === 'to');

    if (to.length === 0) {
        return '(no To recipient)';
    }

    const [first, ...rest] = to;

    return rest.length > 0 ? `${first.address} +${rest.length} more` : first.address;
}

/**
 * Corpul unui email e conținut NECONTROLAT (a plecat printr-un `Mailable`/`Notification`
 * oarecare, nu de la un utilizator din acest workspace, dar tot nu e „al nostru"). Niciodată
 * `dangerouslySetInnerHTML` pe HTML brut:
 *   - Cu `textBody` disponibil (aproape mereu — orice `Mailable` cu `Markdown`/vedere text
 *     are ambele părți), randăm PLAIN TEXT într-un `<pre>`.
 *   - Doar dacă NU există deloc `textBody`, izolăm `htmlBody` într-un `<iframe>` cu
 *     `sandbox=""` GOL (fără `allow-scripts`, fără `allow-same-origin`): originea devine
 *     opacă, deci scripturile nu rulează și niciun cookie/CSRF al aplicației nu e accesibil
 *     din interior — un HTML complet necontrolat, izolat, nu randat direct în DOM-ul paginii.
 */
function EmailBodyPreview({ htmlBody, textBody }: { htmlBody: string | null; textBody: string | null }) {
    if (textBody) {
        return (
            <pre className="max-h-96 overflow-auto whitespace-pre-wrap rounded-md border border-border bg-raised p-3 text-xs text-text">
                {textBody}
            </pre>
        );
    }

    if (htmlBody) {
        return (
            <iframe
                title="Email body preview"
                sandbox=""
                srcDoc={htmlBody}
                className="h-96 w-full rounded-md border border-border bg-white"
            />
        );
    }

    return <p className="text-sm text-text-2">No body was captured for this email.</p>;
}

function EmailRow({ email }: { email: SentEmailRow }) {
    const [expanded, setExpanded] = useState(false);
    const detailId = `sent-email-${email.id}-detail`;

    return (
        <>
            <tr className="border-b border-border-soft last:border-0">
                <td className="px-4 py-3 whitespace-nowrap text-text-2">
                    <time dateTime={email.createdAt} className="numeric">
                        {formatWhen(email.createdAt)}
                    </time>
                </td>
                <td className="px-4 py-3">
                    <div className="font-medium text-text">{recipientSummary(email)}</div>
                    {email.recipients.length > 1 && (
                        <div className="text-text-2">{email.recipients.length} recipients total (to/cc/bcc)</div>
                    )}
                </td>
                <td className="px-4 py-3 text-text">{email.subject}</td>
                <td className="px-4 py-3 text-text-2">{email.mailer}</td>
                <td className="px-4 py-3">
                    <StatusBadge tone={statusTone(email.status)}>{statusLabel(email.status)}</StatusBadge>
                    {email.redacted && <div className="mt-1 text-xs text-text-2">Link redacted</div>}
                </td>
                <td className="px-4 py-3 text-right">
                    <button
                        type="button"
                        aria-expanded={expanded}
                        aria-controls={detailId}
                        aria-label={`${expanded ? 'Hide' : 'View'} email "${email.subject}" to ${recipientSummary(email)}`}
                        onClick={() => setExpanded((value) => !value)}
                        className="inline-flex min-h-6 items-center rounded px-2 py-1 text-sm font-medium text-accent-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                    >
                        {expanded ? 'Hide' : 'View'}
                    </button>
                </td>
            </tr>
            {expanded && (
                <tr id={detailId} className="border-b border-border-soft bg-raised last:border-0">
                    <td colSpan={6} className="px-4 py-4">
                        <dl className="mb-3 grid gap-x-6 gap-y-2 text-xs text-text-2 sm:grid-cols-2">
                            <div>
                                <dt className="font-medium text-text">From</dt>
                                <dd>{email.fromName ? `${email.fromName} <${email.fromAddress}>` : (email.fromAddress ?? '—')}</dd>
                            </div>
                            <div>
                                <dt className="font-medium text-text">Recipients</dt>
                                <dd className="flex flex-col gap-1">
                                    {email.recipients.map((recipient) => (
                                        <div key={`${recipient.type}-${recipient.address}`}>
                                            <span className="uppercase">{recipient.type}</span>: {recipient.address}{' '}
                                            <StatusBadge tone={recipient.allowed ? 'success' : 'warning'}>
                                                {recipient.allowed ? 'delivered' : 'intercepted'}
                                            </StatusBadge>
                                        </div>
                                    ))}
                                </dd>
                            </div>
                        </dl>
                        <EmailBodyPreview htmlBody={email.htmlBody} textBody={email.textBody} />
                    </td>
                </tr>
            )}
        </>
    );
}

/**
 * Settings → Sent Emails (BR-DEMO-02, specs.md §22.3) — jurnalul emailurilor văzute de
 * `App\Mail\Transport\DemoInterceptingTransport`. Fiecare rând se extinde INLINE (nu un
 * `<dialog>`): niciun focus de gestionat la deschidere/închidere, `aria-expanded` +
 * `aria-controls` sunt suficiente pentru un panou care nu ascunde restul paginii.
 */
export default function SentEmailsIndex() {
    const { sentEmails, list, statuses } = usePage<SentEmailsIndexPageProps>().props;
    const { setFilter } = useListFilters(list);

    return (
        <>
            <Head title="Sent emails" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Sent emails"
                    description="Every transactional email the public demo has tried to send — delivered to the allowlist, or intercepted (specs.md §22.3)."
                />

                <label className="flex w-fit flex-col gap-1 text-sm text-text-2">
                    Status
                    <select
                        value={list.filter.status ?? ''}
                        onChange={(event) => setFilter('status', event.target.value || null)}
                        className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                    >
                        <option value="">Any status</option>
                        {statuses.map((status) => (
                            <option key={status} value={status}>
                                {statusLabel(status)}
                            </option>
                        ))}
                    </select>
                </label>

                <Deferred data="sentEmails" fallback={<TableSkeleton columns={6} />}>
                    {sentEmails && sentEmails.data.length > 0 ? (
                        <div className="overflow-x-auto rounded-lg border border-border">
                            <table className="w-full text-left text-sm">
                                <caption className="sr-only">Sent emails log</caption>
                                <thead className="border-b border-border bg-surface text-text-2">
                                    <tr>
                                        <th scope="col" className="px-4 py-2 font-medium">When</th>
                                        <th scope="col" className="px-4 py-2 font-medium">To</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Subject</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Mailer</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Status</th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            <span className="sr-only">Details</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {sentEmails.data.map((email) => (
                                        <EmailRow key={email.id} email={email} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        sentEmails && <EmptyState message="No emails have been sent or intercepted yet." />
                    )}
                </Deferred>

                {sentEmails && <CursorPagination nextCursor={sentEmails.nextCursor} prevCursor={sentEmails.prevCursor} />}
            </div>
        </>
    );
}

SentEmailsIndex.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
