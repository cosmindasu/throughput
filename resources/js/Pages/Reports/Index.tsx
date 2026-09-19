import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ButtonLink } from '@/Components/Button';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { ReportRunStatus, ReportsIndexPageProps } from '@/types/generated';

const RUN_STATUS_TONES: Record<ReportRunStatus, BadgeTone> = {
    queued: 'neutral',
    running: 'accent',
    success: 'success',
    failed: 'danger',
};

const RUN_STATUS_LABELS: Record<ReportRunStatus, string> = {
    queued: 'Queued',
    running: 'Running',
    success: 'Success',
    failed: 'Failed',
};

const FREQUENCY_LABELS: Record<string, string> = {
    none: 'Manual only',
    daily: 'Daily',
    weekly: 'Weekly',
    monthly: 'Monthly',
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
    const { reports, can, workspace } = usePage<ReportsIndexPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    return (
        <>
            <Head title="Reports" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Reports"
                    actions={can.create && <ButtonLink variant="primary" href={`${base}/reports/create`}>New report</ButtonLink>}
                />

                {reports.length > 0 ? (
                    <div className="overflow-hidden rounded-lg border border-border">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">Reports</caption>
                            <thead className="bg-raised text-text-2">
                                <tr>
                                    <th scope="col" className="px-4 py-2 font-medium">Name</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Source</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Format</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Frequency</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Status</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Last run</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border-soft bg-surface">
                                {reports.map((report) => (
                                    <tr key={report.id} className="hover:bg-row-hover">
                                        <td className="px-4 py-2.5">
                                            <a
                                                href={`${base}/reports/${report.id}`}
                                                className="font-medium text-accent-text hover:underline"
                                            >
                                                {report.name}
                                            </a>
                                        </td>
                                        <td className="px-4 py-2.5 text-text-2">{report.sourceLabel}</td>
                                        <td className="px-4 py-2.5 uppercase text-text-2">{report.format}</td>
                                        <td className="px-4 py-2.5 text-text-2">{FREQUENCY_LABELS[report.scheduleFrequency]}</td>
                                        <td className="px-4 py-2.5">
                                            <StatusBadge tone={report.isActive ? 'success' : 'neutral'}>
                                                {report.isActive ? 'Active' : 'Inactive'}
                                            </StatusBadge>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {report.lastRun ? (
                                                <StatusBadge tone={RUN_STATUS_TONES[report.lastRun.status]}>
                                                    {RUN_STATUS_LABELS[report.lastRun.status]}
                                                </StatusBadge>
                                            ) : (
                                                <span className="text-text-3">Never run</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <EmptyState
                        message={can.create ? 'No reports yet.' : 'No reports have been shared with you yet.'}
                        action={
                            can.create && (
                                <ButtonLink variant="primary" href={`${base}/reports/create`}>
                                    Create your first report
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
