import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ButtonLink } from '@/Components/Button';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { IMPORT_STATUS_LABELS, IMPORT_STATUS_TONES } from '@/lib/importStatus';
import AppLayout from '@/Layouts/AppLayout';
import type { ImportsIndexPageProps, ImportSummary } from '@/types/generated';

/**
 * Imports/Index — §14, US-IMP-01/02. Owner/Manager (`imports.view`) — Agent și Viewer nu
 * ajung aici (nici în navigație, `AppLayout.tsx`, nici la nivel de rută, `ImportPolicy`).
 */
export default function Index() {
    const { imports, can, workspace } = usePage<ImportsIndexPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    return (
        <>
            <Head title="Imports" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Imports"
                    description="Upload a CSV or XLSX file, map its columns, preview what would happen, then commit."
                    actions={can.create && <ButtonLink variant="primary" href={`${base}/imports/create`}>New import</ButtonLink>}
                />

                {imports.length === 0 ? (
                    <EmptyState
                        message="No imports yet."
                        action={can.create && <ButtonLink variant="primary" href={`${base}/imports/create`}>Start your first import</ButtonLink>}
                    />
                ) : (
                    <div className="overflow-hidden rounded-lg border border-border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-raised text-text-2">
                                <tr>
                                    <th scope="col" className="px-4 py-2 font-medium">File</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Resource</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Status</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Rows</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Uploaded by</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Uploaded</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border-soft bg-surface">
                                {imports.map((row) => (
                                    <tr key={row.id} className="hover:bg-row-hover">
                                        <td className="px-4 py-2.5">
                                            <a href={`${base}/imports/${row.id}`} className="font-medium text-accent-text hover:underline">
                                                {row.originalFilename}
                                            </a>
                                        </td>
                                        <td className="px-4 py-2.5 text-text-2">{row.resourceLabel}</td>
                                        <td className="px-4 py-2.5">
                                            <StatusBadge tone={IMPORT_STATUS_TONES[row.status]}>{IMPORT_STATUS_LABELS[row.status]}</StatusBadge>
                                        </td>
                                        <td className="px-4 py-2.5 numeric text-text-2">{formatRowCounts(row)}</td>
                                        <td className="px-4 py-2.5 text-text-2">{row.createdBy?.name ?? '—'}</td>
                                        <td className="px-4 py-2.5 numeric text-text-2">
                                            {row.createdAt ? new Date(row.createdAt).toLocaleString('en-US') : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

function formatRowCounts(row: ImportSummary): string {
    if (row.totalRows === null) {
        return '—';
    }

    if (row.validRows === null || row.errorRows === null) {
        return `${row.totalRows.toLocaleString('en-US')} rows`;
    }

    return `${row.validRows.toLocaleString('en-US')} valid / ${row.errorRows.toLocaleString('en-US')} invalid`;
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
