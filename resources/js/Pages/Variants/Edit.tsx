import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import VariantForm from '@/Components/Products/VariantForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { VariantsEditPageProps } from '@/types/generated';

export default function Edit() {
    const { t } = useTranslation('products');
    const { variant, product, workspace } = usePage<VariantsEditPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';
    // `variant.sku` e conținut scris de utilizator (FR-I18N-06) — interpolat, nu tradus.
    const title = t('products:variant.edit.title', { sku: variant.sku });

    return (
        <>
            <Head title={title} />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader title={title} description={product.name} />
                <VariantForm mode="edit" variant={variant} action={`${base}/variants/${variant.id}`} />
            </div>
        </>
    );
}

Edit.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
