import { Deferred, Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import BulkSelectionBar from '@/Components/BulkSelectionBar';
import { ButtonLink } from '@/Components/Button';
import ColumnSelector, { type ColumnDefinition } from '@/Components/ColumnSelector';
import CursorPagination from '@/Components/CursorPagination';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import SavedViewPicker from '@/Components/SavedViewPicker';
import StatusBadge from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useBulkSelection } from '@/hooks/useBulkSelection';
import { useListColumns } from '@/hooks/useListColumns';
import { useListFilters } from '@/hooks/useListFilters';
import AppLayout from '@/Layouts/AppLayout';
import type { ProductRow, ProductsIndexPageProps } from '@/types/generated';

const dateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' });

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
 */
const PRODUCT_COLUMNS: ProductColumnDef[] = [
    {
        key: 'category',
        label: 'Category',
        cellClassName: 'px-4 py-2.5 text-text-2',
        render: (product) => product.category ?? '—',
    },
    {
        key: 'variantsCount',
        label: 'Variants',
        cellClassName: 'px-4 py-2.5 tabular-nums text-text-2',
        render: (product) => product.variantsCount,
    },
    {
        key: 'isActive',
        label: 'Status',
        cellClassName: 'px-4 py-2.5',
        render: (product) => (
            <StatusBadge tone={product.isActive ? 'success' : 'neutral'}>{product.isActive ? 'Active' : 'Inactive'}</StatusBadge>
        ),
    },
    {
        key: 'lowStock',
        label: 'Low stock',
        cellClassName: 'px-4 py-2.5 tabular-nums',
        // FR-STOCK-02 — `lowStockVariantsCount` vine deja calculat dintr-o subinterogare
        // agregată în `ProductList::baseQuery()` (App\Support\Stock\LowStockRule), nu
        // recalculat aici.
        render: (product) =>
            product.lowStockVariantsCount > 0 ? <StatusBadge tone="warning">{product.lowStockVariantsCount} low</StatusBadge> : '—',
    },
    {
        key: 'createdAt',
        label: 'Created',
        cellClassName: 'px-4 py-2.5 tabular-nums text-text-2',
        render: (product) => (product.createdAt ? dateFormatter.format(new Date(product.createdAt)) : '—'),
    },
];

const PRODUCT_COLUMNS_BY_KEY: Record<string, ProductColumnDef> = Object.fromEntries(
    PRODUCT_COLUMNS.map((column) => [column.key, column] as const),
);

/**
 * Products/Index — specs.md §10, Pachetul A punctul 1. `products` e deferred
 * (FR-PERF-01): shell-ul (filtre, header) apare instant, rândurile vin după.
 *
 * Pachetul C („bulk"), lotul E — preț în masă și activare/dezactivare (§13.5), doar
 * Owner/Manager (`can.bulkWrite`). Fără reasignare de owner (produsele n-au proprietar) și
 * fără export (nu e în tabelul §13.5 pentru Produse/variante).
 */
export default function Index() {
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
        .map((key) => PRODUCT_COLUMNS_BY_KEY[key])
        .filter((column): column is ProductColumnDef => column !== undefined);

    // Identitate (name) + coloanele vizibile + acțiuni-fantomă (Products n-are coloană de
    // acțiuni separată azi, dar rămâne +1 pentru checkbox-ul de bulk când există).
    const skeletonColumnCount = 1 + visibleColumns.length + (can.bulkWrite ? 1 : 0);

    return (
        <>
            <Head title="Products" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Products"
                    actions={
                        <>
                            <SavedViewPicker resourceType="products" current={list} columns={columns} />
                            <ColumnSelector
                                columns={PRODUCT_COLUMNS}
                                selected={columns}
                                onToggle={toggleColumn}
                                onMoveUp={moveColumnUp}
                                onMoveDown={moveColumnDown}
                            />
                            {can.create && <ButtonLink variant="primary" href={`${base}/products/create`}>New product</ButtonLink>}
                        </>
                    }
                />

                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        Search
                        <input
                            type="search"
                            defaultValue={list.filter.q ?? ''}
                            onChange={(event) => setFilter('q', event.target.value)}
                            placeholder="Product name…"
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text placeholder:text-text-3 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        Status
                        <select
                            value={list.filter.status ?? ''}
                            onChange={(event) => setFilter('status', event.target.value || null)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="">Any status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        Sort by
                        <select
                            value={list.sort}
                            onChange={(event) => setSort(event.target.value)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="name">Name</option>
                            <option value="-created_at">Newest</option>
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
                        <div className="overflow-hidden rounded-lg border border-border">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-raised text-text-2">
                                    <tr>
                                        {can.bulkWrite && (
                                            <th scope="col" className="w-10 px-4 py-2">
                                                <input
                                                    type="checkbox"
                                                    aria-label="Select all products on this page"
                                                    checked={selection.allOnPageSelected}
                                                    onChange={selection.toggleAllOnPage}
                                                    className="size-4 rounded border-control"
                                                />
                                            </th>
                                        )}
                                        <th scope="col" className="px-4 py-2 font-medium">Name</th>
                                        {visibleColumns.map((column) => (
                                            <th key={column.key} scope="col" className="px-4 py-2 font-medium">
                                                {column.label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border-soft bg-surface">
                                    {products.data.map((product: ProductRow) => (
                                        <tr key={product.id} className="hover:bg-row-hover">
                                            {can.bulkWrite && (
                                                <td className="px-4 py-2.5">
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`Select ${product.name}`}
                                                        checked={selection.isSelected(product.id)}
                                                        onChange={() => selection.toggleRow(product.id)}
                                                        className="size-4 rounded border-control"
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
                                message={hasFilters ? 'No products match this filter.' : 'No products yet.'}
                                action={
                                    !hasFilters &&
                                    can.create && (
                                        <ButtonLink variant="primary" href={`${base}/products/create`}>
                                            Create your first product
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
