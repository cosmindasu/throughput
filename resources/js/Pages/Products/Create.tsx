import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import ProductForm from '@/Components/Products/ProductForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';

export default function Create() {
    const { workspace } = usePage().props;
    const base = workspace ? `/${workspace.slug}` : '';

    return (
        <>
            <Head title="New product" />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader title="New product" />
                <ProductForm mode="create" action={`${base}/products`} />
            </div>
        </>
    );
}

Create.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
