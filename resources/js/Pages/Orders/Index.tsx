import { Deferred, Head, Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { ButtonLink } from '@/Components/Button';
import CursorPagination from '@/Components/CursorPagination';
import EmptyState from '@/Components/EmptyState';
import { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useListFilters } from '@/hooks/useListFilters';
import AppLayout from '@/Layouts/AppLayout';
import { formatMoney } from '@/lib/money';
import type { OrdersIndexPageProps, OrderStatus, OrderSummary } from '@/types/generated';

const dateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' });

const STATUSES: Array<{ value: OrderStatus | ''; label: string }> = [
    { value: '', label: 'All statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'confirmed', label: 'Confirmed' },
    { value: 'partially_fulfilled', label: 'Partially fulfilled' },
    { value: 'fulfilled', label: 'Fulfilled' },
    { value: 'cancelled', label: 'Cancelled' },
];

const STATUS_TONE: Record<OrderStatus, 'neutral' | 'accent' | 'success' | 'danger'> = {
    draft: 'neutral',
    confirmed: 'accent',
    partially_fulfilled: 'accent',
    fulfilled: 'success',
    cancelled: 'danger',
};

const SORTABLE_COLUMNS: Array<{ key: string; label: string }> = [
    { key: 'order_number', label: 'Order number' },
    { key: 'grand_total', label: 'Grand total' },
    { key: 'placed_at', label: 'Placed at' },
    { key: 'created_at', label: 'Created' },
];

/**
 * `Orders/Index` — FR-ORD-02, plan §9 task 1. Deferred (FR-PERF-01), la fel ca
 * `Deals/Index`: titlul, filtrele și acțiunile sunt pe ecran înainte ca rândurile
 * să vină.
 */
export default function Index() {
    const { props } = usePage<OrdersIndexPageProps>();
    const { filters, can, workspace } = props;
    const workspaceSlug = workspace?.slug ?? '';
    const { setFilter, setSort } = useListFilters(filters);
    const [search, setSearch] = useState(filters.filter.q ?? '');

    const currentSort = filters.sort.replace(/^-/, '');
    const currentDirection = filters.sort.startsWith('-') ? 'desc' : 'asc';

    const toggleSort = (column: string) => {
        if (currentSort !== column) {
            setSort(column);
            return;
        }

        setSort(currentDirection === 'asc' ? `-${column}` : column);
    };

    return (
        <>
            <Head title="Orders" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Orders"
                    actions={can.create ? <ButtonLink variant="primary" href={`/${workspaceSlug}/orders/create`}>New order</ButtonLink> : undefined}
                />

                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-text">Search</span>
                        <input
                            type="search"
                            className={controlClass}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    setFilter('q', search || null);
                                }
                            }}
                            onBlur={() => setFilter('q', search || null)}
                            placeholder="Search by order number…"
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-text">Status</span>
                        <select
                            className={controlClass}
                            value={filters.filter.status ?? ''}
                            onChange={(event) => setFilter('status', event.target.value || null)}
                        >
                            {STATUSES.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <div className="flex overflow-hidden rounded-md border border-control text-sm">
                        <OwnerFilterButton active={(filters.filter.owner ?? 'all') === 'me'} onClick={() => setFilter('owner', 'me')}>
                            My orders
                        </OwnerFilterButton>
                        <OwnerFilterButton active={(filters.filter.owner ?? 'all') === 'all'} onClick={() => setFilter('owner', 'all')}>
                            All orders
                        </OwnerFilterButton>
                    </div>
                </div>

                <Deferred data="orders" fallback={<TableSkeleton columns={6} />}>
                    <OrdersTable workspaceSlug={workspaceSlug} canCreate={can.create} sort={{ column: currentSort, direction: currentDirection }} onSort={toggleSort} />
                </Deferred>
            </div>
        </>
    );
}

function OwnerFilterButton({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={`px-3 py-1.5 transition-colors ${active ? 'bg-accent-fill text-accent-on' : 'bg-surface text-text-2 hover:bg-row-hover'}`}
        >
            {children}
        </button>
    );
}

function OrdersTable({
    workspaceSlug,
    canCreate,
    sort,
    onSort,
}: {
    workspaceSlug: string;
    canCreate: boolean;
    sort: { column: string; direction: 'asc' | 'desc' };
    onSort: (column: string) => void;
}) {
    const { orders } = usePage<OrdersIndexPageProps>().props;

    if (orders.data.length === 0) {
        return (
            <EmptyState
                message="No orders match this filter."
                action={canCreate ? <p className="text-xs text-text-3">Start one from "New order", or from an account page.</p> : undefined}
            />
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="overflow-x-auto rounded-lg border border-border bg-surface">
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b border-border-soft text-xs text-text-3">
                            {SORTABLE_COLUMNS.map((column) => (
                                <th key={column.key} scope="col" className={`px-4 py-2 font-medium ${column.key === 'grand_total' ? 'text-right' : ''}`}>
                                    <button
                                        type="button"
                                        onClick={() => onSort(column.key)}
                                        className={`flex items-center gap-1 hover:text-text ${column.key === 'grand_total' ? 'ml-auto' : ''}`}
                                    >
                                        {column.label}
                                        {sort.column === column.key && <span aria-hidden="true">{sort.direction === 'asc' ? '↑' : '↓'}</span>}
                                    </button>
                                </th>
                            ))}
                            <th scope="col" className="px-4 py-2 font-medium">
                                Status
                            </th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                Account
                            </th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                Owner
                            </th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders.data.map((order) => (
                            <OrderRow key={order.id} order={order} workspaceSlug={workspaceSlug} />
                        ))}
                    </tbody>
                </table>
            </div>

            <CursorPagination nextCursor={orders.nextCursor} prevCursor={orders.prevCursor} />
        </div>
    );
}

function OrderRow({ order, workspaceSlug }: { order: OrderSummary; workspaceSlug: string }) {
    return (
        <tr className="border-b border-border-soft last:border-b-0 hover:bg-row-hover">
            <td className="px-4 py-2">
                <Link href={`/${workspaceSlug}/orders/${order.id}`} className="font-medium text-text hover:underline">
                    {order.orderNumber ?? `Draft #${order.id.slice(-8)}`}
                </Link>
            </td>
            <td className="numeric whitespace-nowrap px-4 py-2 text-right">{formatMoney(order.grandTotal, order.currency)}</td>
            <td className="whitespace-nowrap px-4 py-2">{order.placedAt ? dateFormatter.format(new Date(order.placedAt)) : '—'}</td>
            <td className="whitespace-nowrap px-4 py-2">{order.createdAt ? dateFormatter.format(new Date(order.createdAt)) : '—'}</td>
            <td className="px-4 py-2">
                <StatusBadge tone={STATUS_TONE[order.status]}>{order.statusLabel}</StatusBadge>
            </td>
            <td className="px-4 py-2">{order.account.name}</td>
            <td className="px-4 py-2">{order.owner.name}</td>
            <td className="px-4 py-2 text-text-2">
                {order.can.edit && (
                    <Link href={`/${workspaceSlug}/orders/${order.id}/edit`} className="hover:underline">
                        Edit
                    </Link>
                )}
            </td>
        </tr>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
