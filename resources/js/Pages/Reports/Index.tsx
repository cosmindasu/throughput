import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ButtonLink } from '@/Components/Button';
import ChartSkeleton from '@/Components/Charts/ChartSkeleton';
import EmptyState from '@/Components/EmptyState';
import DeferredData from '@/Components/DeferredData';
import PageHeader from '@/Components/PageHeader';
import ReportInsights from '@/Components/Reports/ReportInsights';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { ReportRunStatus, ReportsIndexPageProps } from '@/types/generated';

const RUN_STATUS_TONES: Record<ReportRunStatus, BadgeTone> = {
    queued: 'neutral',
    running: 'accent',
    success: 'success',
    failed: 'danger',
};

/**
 * Reports/Index — specs.md §16, „lista rapoartelor (nume, sursă, format, frecvență,
 * activ, ultima rulare)". Fără paginare pe cursor (spre deosebire de Accounts/Orders): un
 * tenant are, realist, câteva rapoarte programate, nu mii — o listă simplă e suficientă și
 * nu adaugă mecanismul de coloane/bulk care n-are sens aici (§7.4 nu dă Operații în masă pe
 * rapoarte).
 *
 * Îngustarea ABAC a Agentului (doar rapoartele unde e destinatar, §7.4) e deja aplicată pe
 * server (`ReportRecipients::scopeVisibleTo`) — pagina doar randează ce a primit.
 */
export default function Index() {
    const { t } = useTranslation('reports');
    const { reports, can, insights, workspace } = usePage<ReportsIndexPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    return (
        <>
            <Head title={t('reports:index.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('reports:index.title')}
                    actions={
                        can.create && (
                            <ButtonLink variant="primary" href={`${base}/reports/create`}>
                                {t('reports:index.newReport')}
                            </ButtonLink>
                        )
                    }
                />

                {/*
                    Graficele stau DEASUPRA listei: lista spune când pleacă rapoartele, ele
                    spun ce scrie în ele — iar a doua întrebare e cea pentru care intră cineva
                    pe „Reports". `null` pentru Agent (vezi controllerul), caz în care nu se
                    randează nimic, nici măcar scheletul.
                */}
                {insights !== null && (
                    <DeferredData<ReportsIndexPageProps, 'insights'> keys={['insights']} fallback={<ChartSkeleton height={220} />}>
                        {({ insights: resolved }) => <ReportInsights velocity={resolved.velocity} />}
                    </DeferredData>
                )}

                {reports.length > 0 ? (
                    <div className="overflow-hidden rounded-lg border border-border">
                        <table className="data-table w-full text-left text-sm">
                            <caption className="sr-only">{t('reports:index.title')}</caption>
                            <thead className="bg-raised text-text-2">
                                <tr>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        {t('reports:index.columns.name')}
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        {t('reports:index.columns.source')}
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        {t('reports:index.columns.format')}
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        {t('reports:index.columns.frequency')}
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        {t('reports:index.columns.status')}
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        {t('reports:index.columns.lastRun')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border-soft bg-surface">
                                {reports.map((report) => (
                                    <tr key={report.id}>
                                        <td className="px-4 py-2.5">
                                            {/* `report.name` e conținut scris de utilizator (FR-I18N-06) —
                                                niciodată tradus, nici retradus la comutarea limbii. */}
                                            <a
                                                href={`${base}/reports/${report.id}`}
                                                className="font-medium text-accent-text hover:underline"
                                            >
                                                {report.name}
                                            </a>
                                        </td>
                                        {/* `report.sourceLabel` vine deja tradus din backend — nu se retraduce. */}
                                        <td className="px-4 py-2.5 text-text-2">{report.sourceLabel}</td>
                                        {/* `report.format` (csv/xlsx/pdf) e un cod de format tehnic,
                                            identic în orice limbă — nu se traduce (ca „SKU"/„PDF"). */}
                                        <td className="px-4 py-2.5 uppercase text-text-2">{report.format}</td>
                                        <td className="px-4 py-2.5 text-text-2">{t(`reports:frequency.${report.scheduleFrequency}`)}</td>
                                        <td className="px-4 py-2.5">
                                            <StatusBadge tone={report.isActive ? 'success' : 'neutral'}>
                                                {report.isActive ? t('reports:status.active') : t('reports:status.inactive')}
                                            </StatusBadge>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {report.lastRun ? (
                                                <StatusBadge tone={RUN_STATUS_TONES[report.lastRun.status]}>
                                                    {t(`reports:runStatus.${report.lastRun.status}`)}
                                                </StatusBadge>
                                            ) : (
                                                <span className="text-text-3">{t('reports:index.neverRun')}</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <EmptyState
                        message={can.create ? t('reports:index.empty.ownerView') : t('reports:index.empty.recipientView')}
                        action={
                            can.create && (
                                <ButtonLink variant="primary" href={`${base}/reports/create`}>
                                    {t('reports:index.createFirst')}
                                </ButtonLink>
                            )
                        }
                    />
                )}
            </div>
        </>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
