import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import ReportForm from '@/Components/Reports/ReportForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { ReportsEditPageProps } from '@/types/generated';

export default function Edit() {
    const { t } = useTranslation('reports');
    const { report, savedViews, builtInReports, workspace } = usePage<ReportsEditPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    return (
        <>
            {/* `report.name` e conținut scris de utilizator (FR-I18N-06) — interpolat, nu tradus. */}
            <Head title={t('reports:edit.title', { name: report.name })} />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader title={t('reports:edit.headingQuoted', { name: report.name })} />
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
