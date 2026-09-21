import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import VariantForm from '@/Components/Products/VariantForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { VariantsCreatePageProps } from '@/types/generated';

export default function Create() {
    const { t } = useTranslation('products');
    const { product, workspace } = usePage<VariantsCreatePageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    return (
        <>
            {/* `product.name` e conținut scris de utilizator (FR-I18N-06) — interpolat, nu tradus. */}
            <Head title={t('products:variant.create.title', { name: product.name })} />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader title={t('products:variant.create.heading')} description={product.name} />
                <VariantForm mode="create" action={`${base}/products/${product.id}/variants`} />
            </div>
        </>
    );
}

Create.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
