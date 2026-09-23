import { Deferred, Head, Link, usePage } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useMemo, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import BulkSelectionBar from '@/Components/BulkSelectionBar';
import ColumnSelector, { type ColumnDefinition } from '@/Components/ColumnSelector';
import CursorPagination from '@/Components/CursorPagination';
import ViewSwitcher from '@/Components/Deals/ViewSwitcher';
import EmptyState from '@/Components/EmptyState';
import { controlClass } from '@/Components/Form/Field';
import RowCheckbox from '@/Components/Form/RowCheckbox';
import PageHeader from '@/Components/PageHeader';
import SavedViewPicker from '@/Components/SavedViewPicker';
import StatusBadge from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useBulkSelection } from '@/hooks/useBulkSelection';
import { useListColumns } from '@/hooks/useListColumns';
import { useLocale } from '@/hooks/useLocale';
import { useListFilters } from '@/hooks/useListFilters';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate } from '@/lib/format';
import { type AppLocale } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import type { DealsIndexPageProps, DealSummary } from '@/types/generated';

const buildDealStatusOptions = (t: TFunction): Array<{ value: string; label: string }> => [
    { value: '', label: t('index.statuses.all') },
    { value: 'open', label: t('index.statuses.open') },
    { value: 'won', label: t('index.statuses.won') },
    { value: 'lost', label: t('index.statuses.lost') },
];

interface DealColumnDef extends ColumnDefinition {
    /** Cheia de sortare `ListQuery` (`DealList::sortableColumns()`) — absentă pe coloanele nesortabile (Account/Owner/Stage). */
    sortKey?: string;
    headerClassName?: string;
    cellClassName: string;
    render: (deal: DealSummary) => ReactNode;
}

/**
 * Selector de coloane (specs.md §15.1) — cheile permise pentru Deals, EXACT ca
 * `App\Support\SavedViews\SavedViewResourceType::permittedColumns('deals')`. `title`
 * (identitatea) rămâne fix, randat separat mai jos, cu propriul buton de sortare.
 */
const buildDealColumns = (t: TFunction, locale: AppLocale): DealColumnDef[] => [
    {
        key: 'value',
        label: t('index.columns.value'),
        sortKey: 'value',
        headerClassName: 'text-right',
        cellClassName: 'numeric whitespace-nowrap px-4 py-2 text-right',
        render: (deal) => formatMoney(deal.value, deal.currency, locale),
    },
    {
        key: 'expectedCloseDate',
        label: t('index.columns.expectedClose'),
        sortKey: 'expected_close_date',
        cellClassName: 'whitespace-nowrap px-4 py-2',
        render: (deal) => (deal.expectedCloseDate ? formatDate(deal.expectedCloseDate, locale) : '—'),
    },
    {
        key: 'createdAt',
        label: t('index.columns.created'),
        sortKey: 'created_at',
        cellClassName: 'whitespace-nowrap px-4 py-2',
        render: (deal) => (deal.createdAt ? formatDate(deal.createdAt, locale) : '—'),
    },
    {
        key: 'account',
        label: t('index.columns.account'),
        cellClassName: 'px-4 py-2',
        render: (deal) => deal.account.name,
    },
    {
        key: 'owner',
        label: t('index.columns.owner'),
        cellClassName: 'px-4 py-2',
        render: (deal) => deal.owner.name,
    },
    {
        key: 'stage',
        // Coloana e chrome (eticheta „Stage"); `deal.stage.name` rămas NETRADUS mai jos —
        // e o etapă de pipeline, dată de tenant, nu text de UI (brief Val 3, §4).
        label: t('index.columns.stage'),
        cellClassName: 'px-4 py-2',
        render: (deal) => <StatusBadge>{deal.stage.name}</StatusBadge>,
    },
];

const buildDealColumnsByKey = (columns: DealColumnDef[]): Record<string, DealColumnDef> =>
    Object.fromEntries(columns.map((column) => [column.key, column] as const));

/**
 * `Deals/Index` — task-ul Pachetului C punctul 4. Prop `deals` DEFERRED (FR-PERF-01):
 * titlul, filtrele și acțiunile sunt pe ecran înainte ca rândurile să vină.
 */
export default function Index() {
    const { t } = useTranslation('deals');
    const { props, url } = usePage<DealsIndexPageProps>();
    const locale = useLocale();
    const dealColumns = useMemo(() => buildDealColumns(t, locale), [t, locale]);
    const dealColumnsByKey = useMemo(() => buildDealColumnsByKey(dealColumns), [dealColumns]);
    const dealStatuses = useMemo(() => buildDealStatusOptions(t), [t]);
    const { filters, columns, can, workspace } = props;
    const workspaceSlug = workspace?.slug ?? '';
    const { apply, setFilter, setSort } = useListFilters(filters, columns);
    const { toggle: toggleColumn, moveUp: moveColumnUp, moveDown: moveColumnDown } = useListColumns(columns, apply);
    const [search, setSearch] = useState(filters.filter.q ?? '');
    const bulkDispatchUrl = buildBulkDispatchUrl(url, workspaceSlug ? `/${workspaceSlug}` : '', 'deals');

    const currentSort = filters.sort.replace(/^-/, '');
    const currentDirection = filters.sort.startsWith('-') ? 'desc' : 'asc';

    // Filtrat O SINGURĂ dată, folosit identic pe antet, pe corp ȘI pe skeleton — o cheie
    // necunoscută (n-ar trebui să apară, `columns` e validat server-side, dar defensiv) nu
    // mai dezaliniază tabelul.
    const visibleColumns = columns
        .map((key) => dealColumnsByKey[key])
        .filter((column): column is DealColumnDef => column !== undefined);

    // Identitate (title) + coloanele vizibile + acțiuni, +1 pentru checkbox-ul de bulk când
    // există — altfel skeleton-ul nu se mai potrivește cu tabelul real odată ce selectorul
    // schimbă numărul de coloane.
    const skeletonColumnCount = 1 + visibleColumns.length + 1 + (can.bulkWrite ? 1 : 0);

    const toggleSort = (column: string) => {
        if (currentSort !== column) {
            setSort(column);
            return;
        }

        setSort(currentDirection === 'asc' ? `-${column}` : column);
    };

    return (
        <>
            <Head title={t('index.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('index.title')}
                    actions={
                        <>
                            <SavedViewPicker resourceType="deals" current={filters} columns={columns} />
                            <ColumnSelector columns={dealColumns} selected={columns} onToggle={toggleColumn} onMoveUp={moveColumnUp} onMoveDown={moveColumnDown} />
                            <ViewSwitcher workspaceSlug={workspaceSlug} active="list" />
                        </>
                    }
                />

                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-text">{t('index.search.label')}</span>
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
                            placeholder={t('index.search.placeholder')}
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-text">{t('index.status.label')}</span>
                        <select
                            className={controlClass}
                            value={filters.filter.status ?? ''}
                            onChange={(event) => setFilter('status', event.target.value || null)}
                        >
                            {dealStatuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <div className="flex overflow-hidden rounded-md border border-control text-sm">
                        <OwnerFilterButton active={(filters.filter.owner ?? 'all') === 'me'} onClick={() => setFilter('owner', 'me')}>
                            {t('index.owner.mine')}
                        </OwnerFilterButton>
                        <OwnerFilterButton active={(filters.filter.owner ?? 'all') === 'all'} onClick={() => setFilter('owner', 'all')}>
                            {t('index.owner.all')}
                        </OwnerFilterButton>
                    </div>
                </div>

                <Deferred data="deals" fallback={<TableSkeleton columns={skeletonColumnCount} />}>
                    <DealsTable
                        workspaceSlug={workspaceSlug}
                        canCreate={can.create}
                        columns={visibleColumns}
                        sort={{ column: currentSort, direction: currentDirection }}
                        onSort={toggleSort}
                        bulkDispatchUrl={bulkDispatchUrl}
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

function DealsTable({
    workspaceSlug,
    canCreate,
    columns,
    sort,
    onSort,
    bulkDispatchUrl,
}: {
    workspaceSlug: string;
    canCreate: boolean;
    /** Deja filtrate/rezolvate în `Index()` (`visibleColumns`) — nicio cheie necunoscută aici. */
    columns: DealColumnDef[];
    sort: { column: string; direction: 'asc' | 'desc' };
    onSort: (column: string) => void;
    bulkDispatchUrl: string;
}) {
    const { t } = useTranslation('deals');
    const { deals, total, can, owners, bulkConfirmationThreshold, bulkRowCap } = usePage<DealsIndexPageProps>().props;
    const pageIds = deals.data.map((deal) => deal.id);
    const selection = useBulkSelection(pageIds);

    if (deals.data.length === 0) {
        return (
            <EmptyState
                message={t('index.empty.message')}
                action={
                    canCreate ? (
                        <p className="text-xs text-text-3">{t('index.empty.hint')}</p>
                    ) : undefined
                }
            />
        );
    }

    return (
        <div className="flex flex-col gap-4">
            {can.bulkWrite && (
                <BulkSelectionBar
                    resourceNounSingular="deal"
                    resourceNounPlural="deals"
                    dispatchUrl={bulkDispatchUrl}
                    total={total}
                    selectedCount={selection.selectedCount}
                    allOnPageSelected={selection.allOnPageSelected}
                    matchingFilter={selection.matchingFilter}
                    selectedIds={selection.selectedIds}
                    owners={owners}
                    confirmationThreshold={bulkConfirmationThreshold}
                    rowCap={bulkRowCap}
                    onSelectAllMatching={selection.selectAllMatching}
                    onClearSelection={selection.clear}
                />
            )}

            <div className="overflow-x-auto rounded-lg border border-border bg-surface">
                <table className="w-full text-left text-sm">
                    <caption className="sr-only">{t('index.table.caption')}</caption>
                    <thead>
                        <tr className="border-b border-border-soft text-xs text-text-3">
                            {can.bulkWrite && (
                                <th scope="col" className="w-10 px-4 py-2">
                                    <RowCheckbox
                                        aria-label={t('index.table.selectAllOnPage')}
                                        checked={selection.allOnPageSelected}
                                        onChange={selection.toggleAllOnPage}
                                    />
                                </th>
                            )}
                            <th scope="col" aria-sort={ariaSortFor('title', sort)} className="px-4 py-2 font-medium">
                                <button type="button" onClick={() => onSort('title')} className="flex items-center gap-1 hover:text-text">
                                    {t('index.table.titleColumn')}
                                    {sort.column === 'title' && (
                                        <>
                                            <span aria-hidden="true">{sort.direction === 'asc' ? '↑' : '↓'}</span>
                                            <span className="sr-only">, {sortStateLabel(t, sort.direction)}</span>
                                        </>
                                    )}
                                </button>
                            </th>
                            {columns.map((column) => (
                                <th
                                    key={column.key}
                                    scope="col"
                                    aria-sort={column.sortKey ? ariaSortFor(column.sortKey, sort) : undefined}
                                    className={`px-4 py-2 font-medium ${column.headerClassName ?? ''}`}
                                >
                                    {column.sortKey ? (
                                        <button
                                            type="button"
                                            onClick={() => column.sortKey && onSort(column.sortKey)}
                                            className={`flex items-center gap-1 hover:text-text ${column.headerClassName === 'text-right' ? 'ml-auto' : ''}`}
                                        >
                                            {column.label}
                                            {sort.column === column.sortKey && (
                                                <>
                                                    <span aria-hidden="true">{sort.direction === 'asc' ? '↑' : '↓'}</span>
                                                    <span className="sr-only">, {sortStateLabel(t, sort.direction)}</span>
                                                </>
                                            )}
                                        </button>
                                    ) : (
                                        column.label
                                    )}
                                </th>
                            ))}
                            <th scope="col" className="px-4 py-2 font-medium">
                                <span className="sr-only">{t('index.table.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {deals.data.map((deal) => (
                            <DealRow
                                key={deal.id}
                                deal={deal}
                                workspaceSlug={workspaceSlug}
                                columns={columns}
                                showCheckbox={can.bulkWrite}
                                selected={selection.isSelected(deal.id)}
                                onToggle={() => selection.toggleRow(deal.id)}
                            />
                        ))}
                    </tbody>
                </table>
            </div>

            <CursorPagination nextCursor={deals.nextCursor} prevCursor={deals.prevCursor} />
        </div>
    );
}

function DealRow({
    deal,
    workspaceSlug,
    columns,
    showCheckbox,
    selected,
    onToggle,
}: {
    deal: DealSummary;
    workspaceSlug: string;
    /** Deja filtrate/rezolvate în `Index()` (`visibleColumns`) — nicio cheie necunoscută aici. */
    columns: DealColumnDef[];
    showCheckbox: boolean;
    selected: boolean;
    onToggle: () => void;
}) {
    const { t } = useTranslation('deals');

    return (
        <tr className="border-b border-border-soft last:border-b-0 hover:bg-row-hover">
            {showCheckbox && (
                <td className="px-4 py-2">
                    <RowCheckbox aria-label={t('index.table.selectRow', { title: deal.title })} checked={selected} onChange={onToggle} />
                </td>
            )}
            <td className="px-4 py-2">
                <Link href={`/${workspaceSlug}/deals/${deal.id}`} className="font-medium text-text hover:underline">
                    {deal.title}
                </Link>
                {deal.status !== 'open' && (
                    <span className="ml-2">
                        <StatusBadge tone={deal.status === 'won' ? 'success' : 'danger'}>
                            {deal.status === 'won' ? t('status.won') : t('status.lost')}
                        </StatusBadge>
                    </span>
                )}
            </td>
            {columns.map((column) => (
                <td key={column.key} className={column.cellClassName}>
                    {column.render(deal)}
                </td>
            ))}
            <td className="px-4 py-2 text-text-2">
                {deal.can.edit && (
                    <Link href={`/${workspaceSlug}/deals/${deal.id}/edit`} className="hover:underline">
                        {t('index.table.edit')}<span className="sr-only"> {deal.title}</span>
                    </Link>
                )}
            </td>
        </tr>
    );
}

/**
 * §13.2 — `DispatchBulkOperationAction` capturează exact filtrul/sortul din querystring-ul
 * curent, ca lista/operația în masă să vadă aceleași rânduri pentru același URL.
 */
function buildBulkDispatchUrl(currentUrl: string, base: string, resourceType: 'accounts' | 'deals'): string {
    const query = currentUrl.split('?')[1];
    const path = `${base}/${resourceType}/bulk/reassign-owner`;

    return query ? `${path}?${query}` : path;
}

/**
 * SC 1.3.1 — starea de sortare a unei coloane e o RELAȚIE din tabel, nu o decorație:
 * săgeata ↑/↓ e `aria-hidden` (corect, e un glif fără sens citit cu voce), deci fără
 * `aria-sort` pe `<th>` un cititor de ecran nu avea nicio cale să afle după ce e sortată
 * lista. Se pune pe CELULA de antet, niciodată pe butonul din ea.
 */
function ariaSortFor(column: string, sort: { column: string; direction: 'asc' | 'desc' }): 'ascending' | 'descending' | undefined {
    if (sort.column !== column) {
        return undefined;
    }

    return sort.direction === 'asc' ? 'ascending' : 'descending';
}

/**
 * A11Y-06 (audit) — SC 2.5.3 (Label in Name): starea de sortare intră în numele accesibil
 * al BUTONULUI printr-un `<span className="sr-only">` lângă săgeata `aria-hidden`, NU prin
 * `aria-label` (care ar înlocui textul vizibil). `aria-sort` de pe `<th>` (deja corect, mai
 * sus) e anunțat fiabil doar în modul de navigare pe tabel al unui cititor de ecran, nu la
 * Tab+Enter direct pe control.
 */
function sortStateLabel(t: TFunction, direction: 'asc' | 'desc'): string {
    return direction === 'asc' ? t('index.table.sortedAscending') : t('index.table.sortedDescending');
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
