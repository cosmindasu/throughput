import { Head, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import Button from '@/Components/Button';
import CursorPagination from '@/Components/CursorPagination';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { ActivityIndexPageProps } from '@/types/generated';

const dateTimeFormatter = new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

/**
 * FR-AUD-03, §17.3 — jurnal de activitate TENANT-WIDE, filtrabil pe acțiune, utilizator și
 * interval de dată. Owner/Manager văd tot tenantul; un Agent ajuns aici vede doar propriile
 * acțiuni (`canFilterByUser=false`, `members=[]` — server-side, nu ascuns doar în UI).
 *
 * Filtrele fac un `router.get()` simplu, cu querystring propriu (`action`/`userId`/`from`/
 * `to`/`bulkOperationId`) — distinct de `?filter[...]` din `App\Support\ListQuery`
 * (Accounts/Deals/Orders): acest ecran nu are vederi salvate, nici selector de coloane,
 * deci n-avea rost să tragă întreg mecanismul `useListFilters` după el.
 */
export default function Index() {
    const { entries, filters, actions, members, canFilterByUser } = usePage<ActivityIndexPageProps>().props;
    const { url } = usePage();
    const path = url.split('?')[0];

    const apply = (next: Partial<typeof filters>) => {
        const merged = { ...filters, ...next };
        const query: Record<string, string> = {};

        (Object.keys(merged) as Array<keyof typeof merged>).forEach((key) => {
            const value = merged[key];
            if (value) {
                query[key] = value;
            }
        });

        router.get(path, query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const hasFilters = Object.values(filters).some((value) => value);

    return (
        <>
            <Head title="Activity log" />

            <div className="flex flex-col gap-6">
                <PageHeader title="Activity log" description="Every change made to accounts, contacts, deals, products and orders." />

                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        Action
                        <select
                            value={filters.action ?? ''}
                            onChange={(event) => apply({ action: event.target.value || null })}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="">Any action</option>
                            {actions.map((action) => (
                                <option key={action} value={action}>
                                    {action}
                                </option>
                            ))}
                        </select>
                    </label>

                    {canFilterByUser && (
                        <label className="flex flex-col gap-1 text-sm text-text-2">
                            Member
                            <select
                                value={filters.userId ?? ''}
                                onChange={(event) => apply({ userId: event.target.value || null })}
                                className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                            >
                                <option value="">Anyone</option>
                                {members.map((member) => (
                                    <option key={member.id} value={member.id}>
                                        {member.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        From
                        <input
                            type="date"
                            value={filters.from ?? ''}
                            onChange={(event) => apply({ from: event.target.value || null })}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        To
                        <input
                            type="date"
                            value={filters.to ?? ''}
                            onChange={(event) => apply({ to: event.target.value || null })}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        />
                    </label>

                    {hasFilters && (
                        <Button onClick={() => apply({ action: null, userId: null, from: null, to: null, bulkOperationId: null })}>
                            Clear filters
                        </Button>
                    )}
                </div>

                {/* SC 2.4.4 — textul linkului trebuie să spună unde DUCE. Varianta dinainte
                    era „…rows written by [this bulk operation]", ceea ce promite navigarea
                    spre operația în masă; clicul, de fapt, ȘTERGE filtrul. Starea și
                    acțiunea sunt acum două lucruri separate: propoziția descrie ce se vede,
                    butonul spune ce face. Și e un `<button>`, nu un `<a href>` cu
                    `preventDefault()` — nu era o navigare, deci nu era un link. */}
                {filters.bulkOperationId && (
                    <p className="text-sm text-text-2">
                        Showing only rows written by a single bulk operation.{' '}
                        <button
                            type="button"
                            className="text-accent-text underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                            onClick={() => apply({ bulkOperationId: null })}
                        >
                            Show all rows
                        </button>
                    </p>
                )}

                {entries.data.length === 0 ? (
                    <EmptyState message={hasFilters ? 'No activity matches this filter.' : 'No activity recorded yet.'} />
                ) : (
                    <div className="overflow-hidden rounded-lg border border-border">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">Activity log</caption>
                            <thead className="bg-raised text-text-2">
                                <tr>
                                    <th scope="col" className="px-4 py-2 font-medium">Action</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Member</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Changes</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Date</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border-soft bg-surface">
                                {entries.data.map((entry) => (
                                    <tr key={entry.id} className="hover:bg-row-hover">
                                        <td className="px-4 py-2.5">
                                            {entry.entityUrl ? (
                                                <a href={entry.entityUrl} className="font-medium text-accent-text hover:underline">
                                                    {entry.actionLabel}
                                                </a>
                                            ) : (
                                                <span className="font-medium text-text">{entry.actionLabel}</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5 text-text-2">{entry.actor?.name ?? 'System'}</td>
                                        <td className="px-4 py-2.5 text-xs text-text-3">
                                            {summarizeChangedFields(entry.oldValues, entry.newValues)}
                                        </td>
                                        <td className="numeric px-4 py-2.5 text-text-2">
                                            {entry.createdAt ? dateTimeFormatter.format(new Date(entry.createdAt)) : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <CursorPagination nextCursor={entries.nextCursor} prevCursor={entries.prevCursor} />
            </div>
        </>
    );
}

function summarizeChangedFields(oldValues: Record<string, unknown> | null, newValues: Record<string, unknown> | null): string {
    const fields = Array.from(new Set([...Object.keys(oldValues ?? {}), ...Object.keys(newValues ?? {})]));

    return fields.length > 0 ? fields.join(', ') : '—';
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
