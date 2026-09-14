import { Deferred, Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import CursorPagination from '@/Components/CursorPagination';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useListFilters } from '@/hooks/useListFilters';
import AppLayout from '@/Layouts/AppLayout';
import type { StockHistoryPageProps, StockMovementReason, StockMovementRow } from '@/types/generated';

/**
 * Stock/History — FR-STOCK-03. Fiecare mișcare e o linie a registrului append-only
 * (ADR-004): nimic pe acest ecran se editează sau se șterge, indiferent de rol.
 */
export default function History() {
    const { variant, movements, list, reasons } = usePage<StockHistoryPageProps>().props;
    const { setFilter, setSort } = useListFilters(list);
    const hasFilters = Object.keys(list.filter).length > 0;

    return (
        <>
            <Head title={`Stock history — ${variant.sku}`} />

            <div className="flex flex-col gap-6">
                <PageHeader title={`Stock history — ${variant.sku}`} description={variant.productName} />

                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        Reason
                        <select
                            value={list.filter.reason ?? ''}
                            onChange={(event) => setFilter('reason', event.target.value || null)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="">Any reason</option>
                            {reasons.map((reason) => (
                                <option key={reason} value={reason}>
                                    {reason}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        From
                        <input
                            type="date"
                            value={list.filter.from ?? ''}
                            onChange={(event) => setFilter('from', event.target.value || null)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        To
                        <input
                            type="date"
                            value={list.filter.to ?? ''}
                            onChange={(event) => setFilter('to', event.target.value || null)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        Sort by
                        <select
                            value={list.sort}
                            onChange={(event) => setSort(event.target.value)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="-created_at">Newest</option>
                            <option value="created_at">Oldest</option>
                        </select>
                    </label>
                </div>

                <Deferred data="movements" fallback={<TableSkeleton columns={6} />}>
                    {movements && movements.data.length > 0 ? (
                        <div className="overflow-hidden rounded-lg border border-border">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-raised text-text-2">
                                    <tr>
                                        <th scope="col" className="px-4 py-2 font-medium">Date</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Reason</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Location</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Change</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Note</th>
                                        <th scope="col" className="px-4 py-2 font-medium">By</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border-soft bg-surface">
                                    {movements.data.map((movement: StockMovementRow) => (
                                        <tr key={movement.id}>
                                            <td className="px-4 py-2.5 text-text-2">
                                                {movement.createdAt ? new Date(movement.createdAt).toLocaleString('en-US') : '—'}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <StatusBadge tone={reasonTone(movement.reason)}>{movement.reason}</StatusBadge>
                                            </td>
                                            <td className="px-4 py-2.5 text-text-2">{movement.location.name}</td>
                                            <td className={`px-4 py-2.5 tabular-nums font-medium ${movement.delta >= 0 ? 'text-success' : 'text-danger'}`}>
                                                {movement.delta >= 0 ? `+${movement.delta}` : movement.delta}
                                            </td>
                                            <td className="px-4 py-2.5 text-text-2">{movement.note ?? '—'}</td>
                                            <td className="px-4 py-2.5 text-text-2">{movement.createdBy?.name ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        movements && <EmptyState message={hasFilters ? 'No movements match this filter.' : 'No stock movements recorded yet.'} />
                    )}
                </Deferred>

                {movements && <CursorPagination nextCursor={movements.nextCursor} prevCursor={movements.prevCursor} />}
            </div>
        </>
    );
}

function reasonTone(reason: StockMovementReason): BadgeTone {
    return {
        receipt: 'success' as const,
        sale: 'info' as const,
        adjustment: 'warning' as const,
        return: 'accent' as const,
        transfer: 'neutral' as const,
    }[reason];
}

History.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
