import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import ReportForm from '@/Components/Reports/ReportForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { ReportsCreatePageProps } from '@/types/generated';

export default function Create() {
    const { savedViews, builtInReports, workspace } = usePage<ReportsCreatePageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    return (
        <>
            <Head title="New report" />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader title="New report" />
                <ReportForm mode="create" savedViews={savedViews} builtInReports={builtInReports} action={`${base}/reports`} />
            </div>
        </>
    );
}

Create.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
