import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import VariantForm from '@/Components/Products/VariantForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { VariantsCreatePageProps } from '@/types/generated';

export default function Create() {
    const { product, workspace } = usePage<VariantsCreatePageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    return (
        <>
            <Head title={`New variant — ${product.name}`} />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader title="New variant" description={product.name} />
                <VariantForm mode="create" action={`${base}/products/${product.id}/variants`} />
            </div>
        </>
    );
}

Create.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
