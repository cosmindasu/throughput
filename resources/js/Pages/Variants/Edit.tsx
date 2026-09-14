import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import VariantForm from '@/Components/Products/VariantForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { VariantsEditPageProps } from '@/types/generated';

export default function Edit() {
    const { variant, product, workspace } = usePage<VariantsEditPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    return (
        <>
            <Head title={`Edit ${variant.sku}`} />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader title={`Edit ${variant.sku}`} description={product.name} />
                <VariantForm mode="edit" variant={variant} action={`${base}/variants/${variant.id}`} />
            </div>
        </>
    );
}

Edit.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
