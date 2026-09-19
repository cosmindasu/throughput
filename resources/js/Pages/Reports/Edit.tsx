import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import ReportForm from '@/Components/Reports/ReportForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { ReportsEditPageProps } from '@/types/generated';

export default function Edit() {
    const { report, savedViews, builtInReports, workspace } = usePage<ReportsEditPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    return (
        <>
            <Head title={`Edit ${report.name}`} />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader title={`Edit “${report.name}”`} />
                <ReportForm
                    mode="edit"
                    report={report}
                    savedViews={savedViews}
                    builtInReports={builtInReports}
                    action={`${base}/reports/${report.id}`}
                />
            </div>
        </>
    );
}

Edit.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
