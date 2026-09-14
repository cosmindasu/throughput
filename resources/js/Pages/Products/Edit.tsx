import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import ProductForm from '@/Components/Products/ProductForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { ProductsFormPageProps } from '@/types/generated';

export default function Edit() {
    const { product, workspace } = usePage<ProductsFormPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    if (!product) {
        return null;
    }

    return (
        <>
            <Head title={`Edit ${product.name}`} />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader title={`Edit ${product.name}`} />
                <ProductForm mode="edit" product={product} action={`${base}/products/${product.id}`} />
            </div>
        </>
    );
}

Edit.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
