import { Deferred, Head, usePage } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useMemo, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import BulkSelectionBar from '@/Components/BulkSelectionBar';
import { ButtonLink } from '@/Components/Button';
import ColumnSelector, { type ColumnDefinition } from '@/Components/ColumnSelector';
import CursorPagination from '@/Components/CursorPagination';
import EmptyState from '@/Components/EmptyState';
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
import type { ProductRow, ProductsIndexPageProps } from '@/types/generated';

interface ProductColumnDef extends ColumnDefinition {
    cellClassName: string;
    render: (product: ProductRow) => ReactNode;
}

/**
 * Selector de coloane (specs.md §15.1) — cheile permise pentru Products, EXACT ca
 * `App\Support\SavedViews\SavedViewResourceType::permittedColumns('products')`. Ordinea
 * de aici e ordinea implicită/vizuală (`defaultColumns`): `category`/`variantsCount`/
 * `isActive` se vedeau deja; `lowStock` e implicită de la construcție (FR-STOCK-02, alerta
 * trebuie vizibilă fără acțiune din partea utilizatorului); `createdAt` rămâne opțională.
 *
 * Fabrică parametrizată `(t, locale)` (Val 3, „Lot I18N") — tiparul din `Pages/Deals/
 * Index.tsx`/`Pages/Orders/Index.tsx`, unde doar jumătatea de `locale` era făcută.
 */
const buildProductColumns = (t: TFunction, locale: AppLocale): ProductColumnDef[] => [
    {
        key: 'category',
        label: t('products:index.columns.category'),
        cellClassName: 'px-4 py-2.5 text-text-2',
        render: (product) => product.category ?? '—',
    },
    {
        key: 'variantsCount',
        label: t('products:index.columns.variants'),
        cellClassName: 'px-4 py-2.5 tabular-nums text-text-2',
        render: (product) => product.variantsCount,
    },
    {
        key: 'isActive',
        label: t('products:labels.status'),
        cellClassName: 'px-4 py-2.5',
        render: (product) => (
            <StatusBadge tone={product.isActive ? 'success' : 'neutral'}>
                {product.isActive ? t('products:badges.active') : t('products:badges.inactive')}
            </StatusBadge>
        ),
    },
    {
        key: 'lowStock',
        label: t('products:badges.lowStock'),
        cellClassName: 'px-4 py-2.5 tabular-nums',
        // FR-STOCK-02 — `lowStockVariantsCount` vine deja calculat dintr-o subinterogare
        // agregată în `ProductList::baseQuery()` (App\Support\Stock\LowStockRule), nu
        // recalculat aici.
        render: (product) =>
            product.lowStockVariantsCount > 0 ? (
                <StatusBadge tone="warning">{t('products:index.lowStockBadge', { count: product.lowStockVariantsCount })}</StatusBadge>
            ) : (
                '—'
            ),
    },
    {
        key: 'createdAt',
        label: t('products:index.columns.created'),
        cellClassName: 'px-4 py-2.5 tabular-nums text-text-2',
        render: (product) => (product.createdAt ? formatDate(product.createdAt, locale) : '—'),
    },
];

const buildProductColumnsByKey = (columns: ProductColumnDef[]): Record<string, ProductColumnDef> =>
    Object.fromEntries(columns.map((column) => [column.key, column] as const));

/**
 * Products/Index — specs.md §10, Pachetul A punctul 1. `products` e deferred
 * (FR-PERF-01): shell-ul (filtre, header) apare instant, rândurile vin după.
 *
 * Pachetul C („bulk"), lotul E — preț în masă și activare/dezactivare (§13.5), doar
 * Owner/Manager (`can.bulkWrite`). Fără reasignare de owner (produsele n-au proprietar) și
 * fără export (nu e în tabelul §13.5 pentru Produse/variante).
 */
export default function Index() {
    const { t } = useTranslation('products');
    const locale = useLocale();
    const productColumns = useMemo(() => buildProductColumns(t, locale), [t, locale]);
    const productColumnsByKey = useMemo(() => buildProductColumnsByKey(productColumns), [productColumns]);
    const { products, total, list, columns, can, bulkConfirmationThreshold, bulkRowCap, workspace } = usePage<ProductsIndexPageProps>().props;
    const { url } = usePage();
    const { apply, setFilter, setSort } = useListFilters(list, columns);
    const { toggle: toggleColumn, moveUp: moveColumnUp, moveDown: moveColumnDown } = useListColumns(columns, apply);
    const base = workspace ? `/${workspace.slug}` : '';
    const hasFilters = Object.keys(list.filter).length > 0;

    const pageIds = products ? products.data.map((product) => product.id) : [];
    const selection = useBulkSelection(pageIds);
    const query = queryFrom(url);

    // Filtrat O SINGURĂ dată, folosit identic pe antet, pe corp ȘI pe skeleton (ca pe
    // Accounts/Deals/Orders) — o cheie necunoscută (n-ar trebui să apară, `columns` e
    // validat server-side, dar defensiv) nu mai dezaliniază tabelul.
    const visibleColumns = columns
        .map((key) => productColumnsByKey[key])
        .filter((column): column is ProductColumnDef => column !== undefined);

    // Identitate (name) + coloanele vizibile + acțiuni-fantomă (Products n-are coloană de
    // acțiuni separată azi, dar rămâne +1 pentru checkbox-ul de bulk când există).
    const skeletonColumnCount = 1 + visibleColumns.length + (can.bulkWrite ? 1 : 0);

    return (
        <>
            <Head title={t('products:index.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('products:index.title')}
                    actions={
                        <>
                            <SavedViewPicker resourceType="products" current={list} columns={columns} />
                            <ColumnSelector
                                columns={productColumns}
                                selected={columns}
                                onToggle={toggleColumn}
                                onMoveUp={moveColumnUp}
                                onMoveDown={moveColumnDown}
                            />
                            {can.create && (
                                <ButtonLink variant="primary" href={`${base}/products/create`}>
                                    {t('products:index.newProduct')}
                                </ButtonLink>
                            )}
                        </>
                    }
                />

                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        {t('products:index.filters.search.label')}
                        <input
                            type="search"
                            defaultValue={list.filter.q ?? ''}
                            onChange={(event) => setFilter('q', event.target.value)}
                            placeholder={t('products:index.filters.search.placeholder')}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text placeholder:text-text-3 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        {t('products:index.filters.status.label')}
                        <select
                            value={list.filter.status ?? ''}
                            onChange={(event) => setFilter('status', event.target.value || null)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="">{t('products:index.filters.status.any')}</option>
                            <option value="active">{t('products:badges.active')}</option>
                            <option value="inactive">{t('products:badges.inactive')}</option>
                        </select>
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        {t('products:index.filters.sort.label')}
                        <select
                            value={list.sort}
                            onChange={(event) => setSort(event.target.value)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="name">{t('products:index.filters.sort.name')}</option>
                            <option value="-created_at">{t('products:index.filters.sort.newest')}</option>
                        </select>
                    </label>
                </div>

                {can.bulkWrite && (
                    <BulkSelectionBar
                        resourceNounSingular="product"
                        resourceNounPlural="products"
                        total={total}
                        selectedCount={selection.selectedCount}
                        allOnPageSelected={selection.allOnPageSelected}
                        matchingFilter={selection.matchingFilter}
                        selectedIds={selection.selectedIds}
                        confirmationThreshold={bulkConfirmationThreshold}
                        rowCap={bulkRowCap}
                        onSelectAllMatching={selection.selectAllMatching}
                        onClearSelection={selection.clear}
                        priceUpdateUrl={`${base}/products/bulk/update-price${query}`}
                        toggleActiveUrl={`${base}/products/bulk/set-active${query}`}
                    />
                )}

                <Deferred data="products" fallback={<TableSkeleton columns={skeletonColumnCount} />}>
                    {products && products.data.length > 0 ? (
                        <div className="data-table-scroll rounded-lg border border-border">
                            <table className="data-table w-full text-left text-sm">
                                <caption className="sr-only">{t('products:index.title')}</caption>
                                <thead className="bg-raised text-text-2">
                                    <tr>
                                        {can.bulkWrite && (
                                            <th scope="col" className="w-10 px-4 py-2">
                                                <RowCheckbox
                                                    aria-label={t('products:index.selectAllAria')}
                                                    checked={selection.allOnPageSelected}
                                                    onChange={selection.toggleAllOnPage}
                                                />
                                            </th>
                                        )}
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('products:index.columns.name')}
                                        </th>
                                        {visibleColumns.map((column) => (
                                            <th key={column.key} scope="col" className="px-4 py-2 font-medium">
                                                {column.label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border-soft bg-surface">
                                    {products.data.map((product: ProductRow) => (
                                        <tr key={product.id} data-selected={selection.isSelected(product.id) ? 'true' : undefined}>
                                            {can.bulkWrite && (
                                                <td className="px-4 py-2.5">
                                                    <RowCheckbox
                                                        aria-label={t('products:index.selectRowAria', { name: product.name })}
                                                        checked={selection.isSelected(product.id)}
                                                        onChange={() => selection.toggleRow(product.id)}
                                                    />
                                                </td>
                                            )}
                                            <td className="px-4 py-2.5">
                                                <a href={`${base}/products/${product.id}`} className="font-medium text-accent-text hover:underline">
                                                    {product.name}
                                                </a>
                                            </td>
                                            {visibleColumns.map((column) => (
                                                <td key={column.key} className={column.cellClassName}>
                                                    {column.render(product)}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        products && (
                            <EmptyState
                                message={hasFilters ? t('products:index.empty.filtered') : t('products:index.empty.none')}
                                action={
                                    !hasFilters &&
                                    can.create && (
                                        <ButtonLink variant="primary" href={`${base}/products/create`}>
                                            {t('products:index.createFirst')}
                                        </ButtonLink>
                                    )
                                }
                            />
                        )
                    )}
                </Deferred>

                {products && <CursorPagination nextCursor={products.nextCursor} prevCursor={products.prevCursor} />}
            </div>
        </>
    );
}

/** §13.2 — filtrul/sortul curent, propagat la orice acțiune bulk, ca la reasignarea de owner. */
function queryFrom(currentUrl: string): string {
    const query = currentUrl.split('?')[1];

    return query ? `?${query}` : '';
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
