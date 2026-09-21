import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import ProductForm from '@/Components/Products/ProductForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { ProductsFormPageProps } from '@/types/generated';

export default function Edit() {
    const { t } = useTranslation('products');
    const { product, workspace } = usePage<ProductsFormPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    if (!product) {
        return null;
    }

    // `product.name` e conținut scris de utilizator (FR-I18N-06) — interpolat, nu tradus.
    const title = t('products:edit.title', { name: product.name });

    return (
        <>
            <Head title={title} />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader title={title} />
                <ProductForm mode="edit" product={product} action={`${base}/products/${product.id}`} />
            </div>
        </>
    );
}

Edit.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
