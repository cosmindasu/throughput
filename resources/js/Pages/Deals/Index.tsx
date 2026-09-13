import { Deferred, Head, Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import CursorPagination from '@/Components/CursorPagination';
import ViewSwitcher from '@/Components/Deals/ViewSwitcher';
import EmptyState from '@/Components/EmptyState';
import { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useListFilters } from '@/hooks/useListFilters';
import AppLayout from '@/Layouts/AppLayout';
import { formatMoney } from '@/lib/money';
import type { DealsIndexPageProps, DealSummary } from '@/types/generated';

const dateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' });

const STATUSES = [
    { value: '', label: 'All statuses' },
    { value: 'open', label: 'Open' },
    { value: 'won', label: 'Won' },
    { value: 'lost', label: 'Lost' },
] as const;

const SORTABLE_COLUMNS: Array<{ key: string; label: string }> = [
    { key: 'title', label: 'Title' },
    { key: 'value', label: 'Value' },
    { key: 'expected_close_date', label: 'Expected close' },
    { key: 'created_at', label: 'Created' },
];

/**
 * `Deals/Index` — task-ul Pachetului C punctul 4. Prop `deals` DEFERRED (FR-PERF-01):
 * titlul, filtrele și acțiunile sunt pe ecran înainte ca rândurile să vină.
 */
export default function Index() {
    const { props } = usePage<DealsIndexPageProps>();
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
            <Head title="Deals" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Deals"
                    actions={<ViewSwitcher workspaceSlug={workspaceSlug} active="list" />}
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
                            placeholder="Search by title…"
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
                            My deals
                        </OwnerFilterButton>
                        <OwnerFilterButton active={(filters.filter.owner ?? 'all') === 'all'} onClick={() => setFilter('owner', 'all')}>
                            All deals
                        </OwnerFilterButton>
                    </div>
                </div>

                <Deferred data="deals" fallback={<TableSkeleton columns={6} />}>
                    <DealsTable workspaceSlug={workspaceSlug} canCreate={can.create} sort={{ column: currentSort, direction: currentDirection }} onSort={toggleSort} />
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

function DealsTable({
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
    const { deals } = usePage<DealsIndexPageProps>().props;

    if (deals.data.length === 0) {
        return (
            <EmptyState
                message="No deals match this filter."
                action={canCreate ? <p className="text-xs text-text-3">Create a deal from an account page.</p> : undefined}
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
                                <th key={column.key} scope="col" className="px-4 py-2 font-medium">
                                    <button type="button" onClick={() => onSort(column.key)} className="flex items-center gap-1 hover:text-text">
                                        {column.label}
                                        {sort.column === column.key && <span aria-hidden="true">{sort.direction === 'asc' ? '↑' : '↓'}</span>}
                                    </button>
                                </th>
                            ))}
                            <th scope="col" className="px-4 py-2 font-medium">
                                Account
                            </th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                Owner
                            </th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                Stage
                            </th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {deals.data.map((deal) => (
                            <DealRow key={deal.id} deal={deal} workspaceSlug={workspaceSlug} />
                        ))}
                    </tbody>
                </table>
            </div>

            <CursorPagination nextCursor={deals.nextCursor} prevCursor={deals.prevCursor} />
        </div>
    );
}

function DealRow({ deal, workspaceSlug }: { deal: DealSummary; workspaceSlug: string }) {
    return (
        <tr className="border-b border-border-soft last:border-b-0 hover:bg-row-hover">
            <td className="px-4 py-2">
                <Link href={`/${workspaceSlug}/deals/${deal.id}`} className="font-medium text-text hover:underline">
                    {deal.title}
                </Link>
                {deal.status !== 'open' && (
                    <span className="ml-2">
                        <StatusBadge tone={deal.status === 'won' ? 'success' : 'danger'}>{deal.status === 'won' ? 'Won' : 'Lost'}</StatusBadge>
                    </span>
                )}
            </td>
            <td className="numeric px-4 py-2">{formatMoney(deal.value, deal.currency)}</td>
            <td className="px-4 py-2">{deal.expectedCloseDate ? dateFormatter.format(new Date(deal.expectedCloseDate)) : '—'}</td>
            <td className="px-4 py-2">{deal.createdAt ? dateFormatter.format(new Date(deal.createdAt)) : '—'}</td>
            <td className="px-4 py-2">{deal.account.name}</td>
            <td className="px-4 py-2">{deal.owner.name}</td>
            <td className="px-4 py-2">
                <StatusBadge>{deal.stage.name}</StatusBadge>
            </td>
            <td className="px-4 py-2 text-text-2">
                {deal.can.edit && (
                    <Link href={`/${workspaceSlug}/deals/${deal.id}/edit`} className="hover:underline">
                        Edit
                    </Link>
                )}
            </td>
        </tr>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
