import { Deferred, Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ButtonLink } from '@/Components/Button';
import CursorPagination from '@/Components/CursorPagination';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useListFilters } from '@/hooks/useListFilters';
import AppLayout from '@/Layouts/AppLayout';
import type { ProductRow, ProductsIndexPageProps } from '@/types/generated';

/**
 * Products/Index — specs.md §10, Pachetul A punctul 1. `products` e deferred
 * (FR-PERF-01): shell-ul (filtre, header) apare instant, rândurile vin după.
 */
export default function Index() {
    const { products, list, can, workspace } = usePage<ProductsIndexPageProps>().props;
    const { setFilter, setSort } = useListFilters(list);
    const base = workspace ? `/${workspace.slug}` : '';
    const hasFilters = Object.keys(list.filter).length > 0;

    return (
        <>
            <Head title="Products" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Products"
                    actions={can.create && <ButtonLink variant="primary" href={`${base}/products/create`}>New product</ButtonLink>}
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

                <Deferred data="products" fallback={<TableSkeleton columns={4} />}>
                    {products && products.data.length > 0 ? (
                        <div className="overflow-hidden rounded-lg border border-border">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-raised text-text-2">
                                    <tr>
                                        <th scope="col" className="px-4 py-2 font-medium">Name</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Category</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Variants</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border-soft bg-surface">
                                    {products.data.map((product: ProductRow) => (
                                        <tr key={product.id} className="hover:bg-row-hover">
                                            <td className="px-4 py-2.5">
                                                <a href={`${base}/products/${product.id}`} className="font-medium text-accent-text hover:underline">
                                                    {product.name}
                                                </a>
                                            </td>
                                            <td className="px-4 py-2.5 text-text-2">{product.category ?? '—'}</td>
                                            <td className="px-4 py-2.5 tabular-nums text-text-2">{product.variantsCount}</td>
                                            <td className="px-4 py-2.5">
                                                <StatusBadge tone={product.isActive ? 'success' : 'neutral'}>
                                                    {product.isActive ? 'Active' : 'Inactive'}
                                                </StatusBadge>
                                            </td>
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

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
