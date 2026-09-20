import { Deferred, Head, Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import BulkSelectionBar from '@/Components/BulkSelectionBar';
import { ButtonLink, buttonClass } from '@/Components/Button';
import ColumnSelector, { type ColumnDefinition } from '@/Components/ColumnSelector';
import CursorPagination from '@/Components/CursorPagination';
import EmptyState from '@/Components/EmptyState';
import { controlClass } from '@/Components/Form/Field';
import RowCheckbox from '@/Components/Form/RowCheckbox';
import PageHeader from '@/Components/PageHeader';
import SavedViewPicker from '@/Components/SavedViewPicker';
import StatusBadge from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useBulkSelection } from '@/hooks/useBulkSelection';
import { useListColumns } from '@/hooks/useListColumns';
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

interface OrderColumnDef extends ColumnDefinition {
    /** Cheia de sortare `ListQuery` (`OrderList::sortableColumns()`) — absentă pe coloanele nesortabile (Status/Account/Owner). */
    sortKey?: string;
    headerClassName?: string;
    cellClassName: string;
    render: (order: OrderSummary) => ReactNode;
}

/**
 * Selector de coloane (specs.md §15.1) — cheile permise pentru Orders, EXACT ca
 * `App\Support\SavedViews\SavedViewResourceType::permittedColumns('orders')`. „Order
 * number" (identitatea) rămâne fix, randat separat mai jos, cu propriul buton de sortare.
 * Ordinea de aici e ordinea implicită/vizuală (`defaultColumns`, ce se vedea deja).
 */
const ORDER_COLUMNS: OrderColumnDef[] = [
    {
        key: 'grandTotal',
        label: 'Grand total',
        sortKey: 'grand_total',
        headerClassName: 'text-right',
        cellClassName: 'numeric whitespace-nowrap px-4 py-2 text-right',
        render: (order) => formatMoney(order.grandTotal, order.currency),
    },
    {
        key: 'placedAt',
        label: 'Placed at',
        sortKey: 'placed_at',
        cellClassName: 'whitespace-nowrap px-4 py-2',
        render: (order) => (order.placedAt ? dateFormatter.format(new Date(order.placedAt)) : '—'),
    },
    {
        key: 'createdAt',
        label: 'Created',
        sortKey: 'created_at',
        cellClassName: 'whitespace-nowrap px-4 py-2',
        render: (order) => (order.createdAt ? dateFormatter.format(new Date(order.createdAt)) : '—'),
    },
    {
        key: 'status',
        label: 'Status',
        cellClassName: 'px-4 py-2',
        render: (order) => <StatusBadge tone={STATUS_TONE[order.status]}>{order.statusLabel}</StatusBadge>,
    },
    {
        key: 'account',
        label: 'Account',
        cellClassName: 'px-4 py-2',
        render: (order) => order.account.name,
    },
    {
        key: 'owner',
        label: 'Owner',
        cellClassName: 'px-4 py-2',
        render: (order) => order.owner.name,
    },
];

const ORDER_COLUMNS_BY_KEY: Record<string, OrderColumnDef> = Object.fromEntries(ORDER_COLUMNS.map((column) => [column.key, column] as const));

type Selection = ReturnType<typeof useBulkSelection>;

/**
 * `Orders/Index` — FR-ORD-02, plan §9 task 1. Deferred (FR-PERF-01), la fel ca
 * `Deals/Index`: titlul, filtrele și acțiunile sunt pe ecran înainte ca rândurile
 * să vină.
 *
 * Pachetul C („bulk"), lotul E — reasignare owner, anulare de draft-uri, export CSV/PDF
 * (§13.5). `orders` se citește direct din props (nu doar în `OrdersTable`, ca înainte):
 * `useBulkSelection`/`BulkSelectionBar` au nevoie de `pageIds` la nivelul ăsta, ca la
 * `Accounts/Index`.
 */
export default function Index() {
    const { props, url } = usePage<OrdersIndexPageProps>();
    const { orders, total, draftTotal, filters, columns, can, owners, bulkConfirmationThreshold, bulkRowCap, workspace } = props;
    const workspaceSlug = workspace?.slug ?? '';
    const base = workspace ? `/${workspace.slug}` : '';
    const { apply, setFilter, setSort } = useListFilters(filters, columns);
    const { toggle: toggleColumn, moveUp: moveColumnUp, moveDown: moveColumnDown } = useListColumns(columns, apply);
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

    const canBulkAnything = can.bulkReassignOwner || can.bulkCancelDrafts;
    const pageIds = orders ? orders.data.map((order) => order.id) : [];
    const selection = useBulkSelection(pageIds);

    // §13.5 — N-ul EFECTIV pe care ANULAREA l-ar atinge pe modul „ids" (checkbox-uri de
    // pagină): doar rândurile selectate ȘI `draft`, nu tot ce e bifat — vezi docblock-ul
    // `BulkSelectionBar.cancelDraftsUrl`. Pe modul „select all matching filter" bara
    // folosește `draftTotal` (deferred, calculat server-side), nu acest număr.
    const draftSelectedCount = orders
        ? orders.data.filter((order) => selection.isSelected(order.id) && order.status === 'draft').length
        : 0;

    // Filtrat O SINGURĂ dată, folosit identic pe antet, pe corp ȘI pe skeleton (ca pe
    // Accounts/Deals) — o cheie necunoscută (n-ar trebui să apară, `columns` e validat
    // server-side, dar defensiv) nu mai dezaliniază tabelul.
    const visibleColumns = columns
        .map((key) => ORDER_COLUMNS_BY_KEY[key])
        .filter((column): column is OrderColumnDef => column !== undefined);

    // Identitate (order number) + coloanele vizibile + acțiuni, +1 pentru checkbox-ul de
    // bulk când există — altfel skeleton-ul nu se mai potrivește cu tabelul real odată ce
    // selectorul schimbă numărul de coloane.
    const skeletonColumnCount = 1 + visibleColumns.length + 1 + (canBulkAnything ? 1 : 0);

    return (
        <>
            <Head title="Orders" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Orders"
                    actions={
                        <>
                            <SavedViewPicker resourceType="orders" current={filters} columns={columns} />
                            <ColumnSelector
                                columns={ORDER_COLUMNS}
                                selected={columns}
                                onToggle={toggleColumn}
                                onMoveUp={moveColumnUp}
                                onMoveDown={moveColumnDown}
                            />
                            {can.export && (
                                <>
                                    <a href={buildExportHref(url, base, 'csv')} className={buttonClass('secondary')}>
                                        Export CSV
                                    </a>
                                    <a href={buildExportHref(url, base, 'pdf')} className={buttonClass('secondary')}>
                                        Export PDF
                                    </a>
                                </>
                            )}
                            {can.create && (
                                <ButtonLink variant="primary" href={`${base}/orders/create`}>
                                    New order
                                </ButtonLink>
                            )}
                        </>
                    }
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

                {canBulkAnything && (
                    <BulkSelectionBar
                        resourceNounSingular="order"
                        resourceNounPlural="orders"
                        total={total}
                        selectedCount={selection.selectedCount}
                        allOnPageSelected={selection.allOnPageSelected}
                        matchingFilter={selection.matchingFilter}
                        selectedIds={selection.selectedIds}
                        confirmationThreshold={bulkConfirmationThreshold}
                        rowCap={bulkRowCap}
                        onSelectAllMatching={selection.selectAllMatching}
                        onClearSelection={selection.clear}
                        dispatchUrl={can.bulkReassignOwner ? buildBulkActionUrl(url, base, 'orders/bulk/reassign-owner') : undefined}
                        owners={can.bulkReassignOwner ? owners : undefined}
                        cancelDraftsUrl={can.bulkCancelDrafts ? buildBulkActionUrl(url, base, 'orders/bulk/cancel-drafts') : undefined}
                        draftTotal={draftTotal}
                        draftSelectedCount={draftSelectedCount}
                    />
                )}

                <Deferred data="orders" fallback={<TableSkeleton columns={skeletonColumnCount} />}>
                    <OrdersTable
                        workspaceSlug={workspaceSlug}
                        canCreate={can.create}
                        canBulk={canBulkAnything}
                        selection={selection}
                        columns={visibleColumns}
                        sort={{ column: currentSort, direction: currentDirection }}
                        onSort={toggleSort}
                    />
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
    canBulk,
    selection,
    columns,
    sort,
    onSort,
}: {
    workspaceSlug: string;
    canCreate: boolean;
    canBulk: boolean;
    selection: Selection;
    /** Deja filtrate/rezolvate în `Index()` (`visibleColumns`) — nicio cheie necunoscută aici. */
    columns: OrderColumnDef[];
    sort: { column: string; direction: 'asc' | 'desc' };
    onSort: (column: string) => void;
}) {
    const { orders } = usePage<OrdersIndexPageProps>().props;

    if (orders.data.length === 0) {
        // Text corectat (raportul E2E) — „or from an account page" trimitea la un link
        // „New order" care nu există pe `Accounts/Show.tsx` (doar „New deal"); singura
        // cale reală azi e butonul „New order" de mai sus.
        return (
            <EmptyState
                message="No orders match this filter."
                action={canCreate ? <p className="text-xs text-text-3">Start one from "New order" above.</p> : undefined}
            />
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="overflow-x-auto rounded-lg border border-border bg-surface">
                <table className="w-full text-left text-sm">
                    <caption className="sr-only">Orders</caption>
                    <thead>
                        <tr className="border-b border-border-soft text-xs text-text-3">
                            {canBulk && (
                                <th scope="col" className="w-10 px-4 py-2">
                                    <RowCheckbox
                                        aria-label="Select all orders on this page"
                                        checked={selection.allOnPageSelected}
                                        onChange={selection.toggleAllOnPage}
                                    />
                                </th>
                            )}
                            <th scope="col" className="px-4 py-2 font-medium">
                                <button
                                    type="button"
                                    onClick={() => onSort('order_number')}
                                    className="flex items-center gap-1 hover:text-text"
                                >
                                    Order number
                                    {sort.column === 'order_number' && (
                                        <span aria-hidden="true">{sort.direction === 'asc' ? '↑' : '↓'}</span>
                                    )}
                                </button>
                            </th>
                            {columns.map((column) => (
                                <th key={column.key} scope="col" className={`px-4 py-2 font-medium ${column.headerClassName ?? ''}`}>
                                    {column.sortKey ? (
                                        <button
                                            type="button"
                                            onClick={() => onSort(column.sortKey!)}
                                            className={`flex items-center gap-1 hover:text-text ${column.headerClassName === 'text-right' ? 'ml-auto' : ''}`}
                                        >
                                            {column.label}
                                            {sort.column === column.sortKey && (
                                                <span aria-hidden="true">{sort.direction === 'asc' ? '↑' : '↓'}</span>
                                            )}
                                        </button>
                                    ) : (
                                        column.label
                                    )}
                                </th>
                            ))}
                            <th scope="col" className="px-4 py-2 font-medium">
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders.data.map((order) => (
                            <OrderRow key={order.id} order={order} workspaceSlug={workspaceSlug} canBulk={canBulk} columns={columns} selection={selection} />
                        ))}
                    </tbody>
                </table>
            </div>

            <CursorPagination nextCursor={orders.nextCursor} prevCursor={orders.prevCursor} />
        </div>
    );
}

function OrderRow({
    order,
    workspaceSlug,
    canBulk,
    columns,
    selection,
}: {
    order: OrderSummary;
    workspaceSlug: string;
    canBulk: boolean;
    /** Deja filtrate/rezolvate în `Index()` (`visibleColumns`) — nicio cheie necunoscută aici. */
    columns: OrderColumnDef[];
    selection: Selection;
}) {
    return (
        <tr className="border-b border-border-soft last:border-b-0 hover:bg-row-hover">
            {canBulk && (
                <td className="px-4 py-2">
                    <RowCheckbox
                        aria-label={`Select ${order.orderNumber ?? 'draft order'}`}
                        checked={selection.isSelected(order.id)}
                        onChange={() => selection.toggleRow(order.id)}
                    />
                </td>
            )}
            <td className="px-4 py-2">
                <Link href={`/${workspaceSlug}/orders/${order.id}`} className="font-medium text-text hover:underline">
                    {order.orderNumber ?? `Draft #${order.id.slice(-8)}`}
                </Link>
            </td>
            {columns.map((column) => (
                <td key={column.key} className={column.cellClassName}>
                    {column.render(order)}
                </td>
            ))}
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

/** §13.2 — filtrul/sortul curent, propagat la export, la fel ca `Accounts/Index`. */
function buildExportHref(currentUrl: string, base: string, format: 'csv' | 'pdf'): string {
    const query = currentUrl.split('?')[1];
    const exportPath = `${base}/orders/export`;
    const formatParam = `format=${format}`;

    return query ? `${exportPath}?${query}&${formatParam}` : `${exportPath}?${formatParam}`;
}

/** §13.2 — capturează exact filtrul/sortul curent, ca lista și operația bulk să vadă aceleași rânduri. */
function buildBulkActionUrl(currentUrl: string, base: string, path: string): string {
    const query = currentUrl.split('?')[1];
    const url = `${base}/${path}`;

    return query ? `${url}?${query}` : url;
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
