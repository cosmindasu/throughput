import { Head, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import Button, { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { ProductsShowPageProps, VariantRow } from '@/types/generated';

/**
 * Products/Show — produsul + variantele lui (specs.md §10.2). Fiecare variantă are
 * link direct spre stocul ei (`Stock/Show`) și istoric (`Stock/History`), plus editare.
 */
export default function Show() {
    const { product, deletionBlockedReason, can, workspace } = usePage<ProductsShowPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const destroy = () => {
        setDeleting(true);
        router.delete(`${base}/products/${product.id}`, {
            onFinish: () => {
                setDeleting(false);
                setConfirmingDelete(false);
            },
        });
    };

    return (
        <>
            <Head title={product.name} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={product.name}
                    description={
                        <span className="flex items-center gap-2">
                            <StatusBadge tone={product.isActive ? 'success' : 'neutral'}>
                                {product.isActive ? 'Active' : 'Inactive'}
                            </StatusBadge>
                            {product.category && <span>{product.category}</span>}
                            <span>·</span>
                            <span>{product.unitOfMeasure}</span>
                        </span>
                    }
                    actions={
                        <>
                            {can.createVariant && (
                                <ButtonLink href={`${base}/products/${product.id}/variants/create`}>Add variant</ButtonLink>
                            )}
                            {can.edit && <ButtonLink href={`${base}/products/${product.id}/edit`}>Edit</ButtonLink>}
                            {can.delete && (
                                <Button variant="danger" onClick={() => setConfirmingDelete(true)}>
                                    Delete
                                </Button>
                            )}
                        </>
                    }
                />

                <section aria-label="Variants" className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">Variants</h2>

                    {product.variants.length === 0 ? (
                        <EmptyState message="No variants yet." />
                    ) : (
                        <div className="overflow-hidden rounded-lg border border-border">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-raised text-text-2">
                                    <tr>
                                        <th scope="col" className="px-4 py-2 font-medium">SKU</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Price</th>
                                        {can.edit && <th scope="col" className="px-4 py-2 font-medium">Cost</th>}
                                        <th scope="col" className="px-4 py-2 font-medium">Available</th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            <span className="sr-only">Low stock</span>
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">Status</th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border-soft bg-surface">
                                    {product.variants.map((variant: VariantRow) => (
                                        <tr key={variant.id} className="hover:bg-row-hover">
                                            <td className="px-4 py-2.5 font-medium text-text">{variant.sku}</td>
                                            <td className="px-4 py-2.5 tabular-nums text-text-2">{formatCurrency(variant.price)}</td>
                                            {can.edit && (
                                                <td className="px-4 py-2.5 tabular-nums text-text-2">
                                                    {variant.cost !== undefined ? formatCurrency(variant.cost) : '—'}
                                                </td>
                                            )}
                                            <td className="px-4 py-2.5 tabular-nums text-text-2">
                                                {variant.available !== undefined ? variant.available : '—'}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                {variant.isLowStock && <StatusBadge tone="warning">Low stock</StatusBadge>}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <StatusBadge tone={variant.isActive ? 'success' : 'neutral'}>
                                                    {variant.isActive ? 'Active' : 'Inactive'}
                                                </StatusBadge>
                                            </td>
                                            <td className="px-4 py-2.5 text-right">
                                                <div className="flex justify-end gap-3">
                                                    <a href={`${base}/variants/${variant.id}/stock`} className="text-accent-text hover:underline">
                                                        Stock
                                                    </a>
                                                    <a href={`${base}/variants/${variant.id}/stock/history`} className="text-accent-text hover:underline">
                                                        History
                                                    </a>
                                                    {can.edit && (
                                                        <a href={`${base}/variants/${variant.id}/edit`} className="text-accent-text hover:underline">
                                                            Edit
                                                        </a>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>

            <ConfirmDialog
                open={confirmingDelete}
                title={deletionBlockedReason ? 'Cannot delete this product' : `Delete ${product.name}?`}
                onConfirm={deletionBlockedReason ? undefined : destroy}
                confirmVariant="danger"
                confirmLabel="Delete"
                processing={deleting}
                onClose={() => setConfirmingDelete(false)}
            >
                {deletionBlockedReason ?? 'This action cannot be undone.'}
            </ConfirmDialog>
        </>
    );
}

function formatCurrency(value: number): string {
    return value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
