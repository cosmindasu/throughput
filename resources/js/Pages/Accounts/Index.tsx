import { Deferred, Head, usePage } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useMemo, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import BulkSelectionBar from '@/Components/BulkSelectionBar';
import Button, { ButtonLink, buttonClass } from '@/Components/Button';
import ColumnSelector, { type ColumnDefinition } from '@/Components/ColumnSelector';
import CursorPagination from '@/Components/CursorPagination';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import RowCheckbox from '@/Components/Form/RowCheckbox';
import SavedViewPicker from '@/Components/SavedViewPicker';
import StatusBadge from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useBulkSelection } from '@/hooks/useBulkSelection';
import { useListColumns } from '@/hooks/useListColumns';
import { useListFilters } from '@/hooks/useListFilters';
import { useLocale } from '@/hooks/useLocale';
import AppLayout from '@/Layouts/AppLayout';
import { formatDateNumeric } from '@/lib/format';
import type { AppLocale } from '@/lib/i18n';
import type { AccountRow, AccountsIndexPageProps } from '@/types/generated';

interface AccountColumnDef extends ColumnDefinition {
    cellClassName: string;
    render: (account: AccountRow) => ReactNode;
}

/**
 * Selector de coloane (specs.md §15.1) — cheile permise pentru Accounts, EXACT ca
 * `App\Support\SavedViews\SavedViewResourceType::permittedColumns('accounts')`. Ordinea de
 * aici e ordinea CANONICĂ (implicit + meniul selectorului); ordinea de AFIȘARE reală e
 * `props.columns` (validat server-side).
 *
 * Fabrică parametrizată pe `(t, locale)` — Val 3, „Lot I18N" (ADR-022, FR-I18N-03):
 * tiparul e cel deja aplicat pentru jumătatea de `locale` în `Pages/Deals/Index.tsx` /
 * `Pages/Orders/Index.tsx`, extins aici și cu `t`. `account.status` e afișat prin
 * `StatusBadge`, deci trece prin catalog (`status.*`, text englez IDENTIC — enum-ul era
 * deja randat lowercase, „active"/„prospect"/„inactive", nu Title Case).
 */
const buildAccountColumns = (t: TFunction<'accounts'>, locale: AppLocale): AccountColumnDef[] => [
    {
        key: 'owner',
        label: t('index.columns.owner'),
        cellClassName: 'px-4 py-2.5 text-text-2',
        render: (account) => account.owner?.name ?? '—',
    },
    {
        key: 'status',
        label: t('index.columns.status'),
        cellClassName: 'px-4 py-2.5',
        render: (account) => <StatusBadge tone={statusTone(account.status)}>{t(`status.${account.status}`)}</StatusBadge>,
    },
    {
        key: 'createdAt',
        label: t('index.columns.created'),
        cellClassName: 'px-4 py-2.5 tabular-nums text-text-2',
        // `toLocaleDateString('en-US')` fără opțiuni → `formatDateNumeric` (Val 3, FR-I18N-03):
        // forma pur numerică, „3/14/2026" în engleză, „14/03/2026" în franceză.
        render: (account) => (account.createdAt ? formatDateNumeric(account.createdAt, locale) : '—'),
    },
];

const buildAccountColumnsByKey = (columns: AccountColumnDef[]): Record<string, AccountColumnDef> =>
    Object.fromEntries(columns.map((column) => [column.key, column] as const));

/**
 * Accounts/Index — FR-CRM-03, US-CRM-02. `accounts` e deferred (FR-PERF-01): shell-ul
 * (filtre, header) apare instant, rândurile vin după — vezi `TableSkeleton`.
 */
export default function Index() {
    const { t } = useTranslation('accounts');
    const locale = useLocale();
    const { accounts, total, list, columns, owners, can, bulkConfirmationThreshold, bulkRowCap, workspace } = usePage<AccountsIndexPageProps>().props;
    const { url } = usePage();
    const { apply, setFilter, setSort } = useListFilters(list, columns);
    const { toggle: toggleColumn, moveUp: moveColumnUp, moveDown: moveColumnDown } = useListColumns(columns, apply);

    const accountColumns = useMemo(() => buildAccountColumns(t, locale), [t, locale]);
    const accountColumnsByKey = useMemo(() => buildAccountColumnsByKey(accountColumns), [accountColumns]);

    const hasFilters = Object.keys(list.filter).length > 0;
    const base = workspace ? `/${workspace.slug}` : '';
    const exportHref = buildExportHref(url, base);
    const bulkDispatchUrl = buildBulkDispatchUrl(url, base, 'accounts');

    const pageIds = accounts ? accounts.data.map((account) => account.id) : [];
    const selection = useBulkSelection(pageIds);

    // Filtrat O SINGURĂ dată și folosit identic pe antet, pe corp ȘI pe skeleton (ca în
    // `Deals/Index.tsx`) — o cheie necunoscută (n-ar trebui să apară, `columns` e validat
    // server-side, dar defensiv) nu mai dezaliniază tabelul: fără asta, antetul randa
    // necondiționat, corpul sărea coloana, iar rândurile se decalau vizual.
    const visibleColumns = columns
        .map((key) => accountColumnsByKey[key])
        .filter((column): column is AccountColumnDef => column !== undefined);

    // Identitate + coloanele vizibile + acțiuni, +1 pentru checkbox-ul de bulk când există —
    // altfel skeleton-ul (lățimea coloanelor „pulsând" înainte ca rândurile să vină, FR-PERF-01)
    // nu se mai potrivește cu tabelul real odată ce selectorul schimbă numărul de coloane.
    const skeletonColumnCount = 1 + visibleColumns.length + 1 + (can.bulkWrite ? 1 : 0);

    return (
        <>
            <Head title={t('index.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('index.title')}
                    actions={
                        <>
                            <SavedViewPicker resourceType="accounts" current={list} columns={columns} />
                            <ColumnSelector columns={accountColumns} selected={columns} onToggle={toggleColumn} onMoveUp={moveColumnUp} onMoveDown={moveColumnDown} />
                            {can.export && (
                                <a href={exportHref} className={buttonClass('secondary')}>
                                    {t('index.exportCsv')}
                                </a>
                            )}
                            {can.create && <ButtonLink variant="primary" href={`${base}/accounts/create`}>{t('index.newAccount')}</ButtonLink>}
                        </>
                    }
                />

                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        {t('index.search.label')}
                        <input
                            type="search"
                            defaultValue={list.filter.q ?? ''}
                            onChange={(event) => setFilter('q', event.target.value)}
                            placeholder={t('index.search.placeholder')}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text placeholder:text-text-3 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        {t('index.filters.owner.label')}
                        <select
                            value={list.filter.owner ?? 'all'}
                            onChange={(event) => setFilter('owner', event.target.value)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="me">{t('index.filters.owner.mine')}</option>
                            <option value="all">{t('index.filters.owner.all')}</option>
                            <option value="unassigned">{t('index.filters.owner.unassigned')}</option>
                            {owners.map((owner) => (
                                <option key={owner.id} value={owner.id}>
                                    {owner.name}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        {t('index.filters.status.label')}
                        <select
                            value={list.filter.status ?? ''}
                            onChange={(event) => setFilter('status', event.target.value || null)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="">{t('index.filters.status.any')}</option>
                            <option value="prospect">{t('index.filters.status.prospect')}</option>
                            <option value="active">{t('index.filters.status.active')}</option>
                            <option value="inactive">{t('index.filters.status.inactive')}</option>
                        </select>
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        {t('index.filters.sort.label')}
                        <select
                            value={list.sort}
                            onChange={(event) => setSort(event.target.value)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="name">{t('index.filters.sort.name')}</option>
                            <option value="-created_at">{t('index.filters.sort.newest')}</option>
                        </select>
                    </label>

                    {hasFilters && <Button onClick={() => clearAll(setFilter)}>{t('index.filters.clear')}</Button>}
                </div>

                {can.bulkWrite && (
                    <BulkSelectionBar
                        resourceNounSingular="account"
                        resourceNounPlural="accounts"
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

                <Deferred data="accounts" fallback={<TableSkeleton columns={skeletonColumnCount} />}>
                    {accounts && accounts.data.length > 0 ? (
                        <div className="data-table-scroll rounded-lg border border-border">
                            <table className="data-table w-full text-left text-sm">
                                <caption className="sr-only">{t('index.title')}</caption>
                                <thead className="bg-raised text-text-2">
                                    <tr>
                                        {can.bulkWrite && (
                                            <th scope="col" className="w-10 px-4 py-2">
                                                <RowCheckbox
                                                    aria-label={t('index.selectAllOnPage')}
                                                    checked={selection.allOnPageSelected}
                                                    onChange={selection.toggleAllOnPage}
                                                />
                                            </th>
                                        )}
                                        <th scope="col" className="px-4 py-2 font-medium">{t('index.columns.name')}</th>
                                        {visibleColumns.map((column) => (
                                            <th key={column.key} scope="col" className="px-4 py-2 font-medium">
                                                {column.label}
                                            </th>
                                        ))}
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            <span className="sr-only">{t('index.actionsColumnLabel')}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border-soft bg-surface">
                                    {accounts.data.map((account: AccountRow) => (
                                        <tr key={account.id} data-selected={selection.isSelected(account.id) ? 'true' : undefined}>
                                            {can.bulkWrite && (
                                                <td className="px-4 py-2.5">
                                                    <RowCheckbox
                                                        aria-label={t('index.selectRow', { name: account.name })}
                                                        checked={selection.isSelected(account.id)}
                                                        onChange={() => selection.toggleRow(account.id)}
                                                    />
                                                </td>
                                            )}
                                            <td className="px-4 py-2.5">
                                                <a href={`${base}/accounts/${account.id}`} className="font-medium text-accent-text hover:underline">
                                                    {account.name}
                                                </a>
                                                {account.domain && <div className="text-xs text-text-3">{account.domain}</div>}
                                            </td>
                                            {visibleColumns.map((column) => (
                                                <td key={column.key} className={column.cellClassName}>
                                                    {column.render(account)}
                                                </td>
                                            ))}
                                            <td className="px-4 py-2.5 text-right">
                                                {/* SC 2.4.4 / 4.1.2 — „Edit" identic pe fiecare rând dă N linkuri
                                                    cu același nume accesibil, imposibil de distins în lista de
                                                    linkuri a unui cititor de ecran. Discriminatorul e `sr-only`
                                                    DUPĂ textul vizibil, nu un `aria-label` care l-ar înlocui:
                                                    numele accesibil tot ÎNCEPE cu textul vizibil (SC 2.5.3 Label
                                                    in Name). Același tipar pe Contacts/Deals/Orders/Products. */}
                                                {account.canEdit && (
                                                    <a href={`${base}/accounts/${account.id}/edit`} className="text-sm text-accent-text hover:underline">
                                                        {t('index.editRow')}<span className="sr-only"> {account.name}</span>
                                                    </a>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        accounts && (
                            <EmptyState
                                message={hasFilters ? t('index.empty.filtered') : t('index.empty.none')}
                                action={
                                    hasFilters ? (
                                        <Button onClick={() => clearAll(setFilter)}>{t('index.filters.clear')}</Button>
                                    ) : (
                                        can.create && (
                                            <ButtonLink variant="primary" href={`${base}/accounts/create`}>
                                                {t('index.empty.createFirst')}
                                            </ButtonLink>
                                        )
                                    )
                                }
                            />
                        )
                    )}
                </Deferred>

                {accounts && <CursorPagination nextCursor={accounts.nextCursor} prevCursor={accounts.prevCursor} />}
            </div>
        </>
    );
}

function statusTone(status: string): 'success' | 'warning' | 'neutral' {
    if (status === 'active') {
        return 'success';
    }

    if (status === 'prospect') {
        return 'warning';
    }

    return 'neutral';
}

function clearAll(setFilter: (key: string, value: string | null) => void): void {
    ['q', 'status', 'owner'].forEach((key) => setFilter(key, key === 'owner' ? 'all' : null));
}

function buildExportHref(currentUrl: string, base: string): string {
    const query = currentUrl.split('?')[1];
    const exportPath = `${base}/accounts/export`;

    return query ? `${exportPath}?${query}` : exportPath;
}

/**
 * §13.2 — `DispatchBulkOperationAction` capturează exact filtrul/sortul din querystring-ul
 * curent, ca lista/exportul/operația în masă să vadă aceleași rânduri pentru același URL.
 */
function buildBulkDispatchUrl(currentUrl: string, base: string, resourceType: 'accounts' | 'deals'): string {
    const query = currentUrl.split('?')[1];
    const path = `${base}/${resourceType}/bulk/reassign-owner`;

    return query ? `${path}?${query}` : path;
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
