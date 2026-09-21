import { Head, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import Button, { buttonClass } from '@/Components/Button';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatDateTime as formatDateTimeLocale } from '@/lib/format';
import type { AppLocale } from '@/lib/i18n';
import type { DataExportIndexPageProps, DataExportRequestRow, DataExportStatus } from '@/types/generated';

const ACTIVE_STATUSES: DataExportStatus[] = ['queued', 'processing'];

const TONES: Record<DataExportStatus, BadgeTone> = {
    queued: 'neutral',
    processing: 'accent',
    completed: 'success',
    failed: 'danger',
};

function formatDateTime(value: string | null, locale: AppLocale): string {
    return value ? formatDateTimeLocale(value, locale) : '—';
}

function statusLabel(row: DataExportRequestRow, t: TFunction<'settings'>): string {
    return t(row.status === 'completed' && row.isExpired ? 'settings:dataExport.status.expired' : `settings:dataExport.status.${row.status}`);
}

function statusTone(row: DataExportRequestRow): BadgeTone {
    return row.status === 'completed' && row.isExpired ? 'neutral' : TONES[row.status];
}

/**
 * Settings → Export data (FR-GDPR-01/02, US-GDPR-01, specs.md §20.5).
 *
 * Owner declanșează, Manager vede doar istoricul — `can.create` vine SERVER-SIDE din
 * Policy (FR-RBAC-01: butonul lipsește, nu e randat dezactivat).
 *
 * Polling la 3 secunde cât există măcar o cerere `queued`/`processing`, cu AMBELE ramuri
 * (`start()` ȘI `stop()`) — `.ai/rules/frontend.md`: `autoStart` se evaluează o singură
 * dată, la montare, iar fluxul real de aici e exact cel în care condiția devine adevărată
 * DUPĂ montare (omul apasă „Request export" pe pagina deja deschisă, iar răspunsul e un
 * redirect înapoi pe ACEEAȘI rută). Fără `start()`, bara ar rămâne pe „Queued" la
 * nesfârșit, deși arhiva e gata pe server.
 */
export default function DataExportIndex() {
    const { requests, can, retentionDays, workspace } = usePage<DataExportIndexPageProps>().props;
    const { t } = useTranslation('settings');
    const locale = useLocale();
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const inProgress = requests.some((request) => ACTIVE_STATUSES.includes(request.status));
    const { start, stop } = usePoll(3000, { only: ['requests'] }, { autoStart: inProgress });

    useEffect(() => {
        if (inProgress) {
            start();
        } else {
            stop();
        }
    }, [inProgress, start, stop]);

    const request = () => {
        if (processing) {
            return;
        }

        setProcessing(true);
        setError(null);

        router.post(
            `/${workspace?.slug}/settings/data-export`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setProcessing(false),
                onError: (pageErrors) => {
                    setProcessing(false);
                    setError(pageErrors.export ?? t('settings:dataExport.errorFallback'));
                },
            },
        );
    };

    return (
        <>
            <Head title={t('settings:dataExport.title')} />

            <PageHeader
                title={t('settings:dataExport.title')}
                description={t('settings:dataExport.description')}
                actions={
                    can.create ? (
                        // Audit de accesibilitate (`.ai/rules/frontend.md`) — `aria-disabled`,
                        // nu `disabled`: browserul blurează un buton dezactivat care are
                        // focus, iar focusul cade pe `<body>`. Starea e spusă și în text.
                        <Button variant="primary" aria-disabled={processing} onClick={request}>
                            {processing ? t('settings:dataExport.requesting') : t('settings:dataExport.request')}
                        </Button>
                    ) : undefined
                }
            />

            {error && (
                <div role="alert" className="mt-4 rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                    {error}
                </div>
            )}

            {/* Notificarea „UI" din FR-GDPR-01. Textul se derivă direct din props, nu
                dintr-un `setState` — o regiune `aria-live` anunță MUTAȚIA DOM-ului, iar un
                `setState` la valoare identică nu produce nicio randare (regula din
                `.ai/rules/frontend.md`). */}
            <p role="status" aria-live="polite" className="mt-4 text-sm text-text-2">
                {inProgress
                    ? t('settings:dataExport.progressMessage')
                    : t('settings:dataExport.retentionMessage', { count: retentionDays })}
            </p>

            <div className="mt-6 overflow-x-auto rounded-lg border border-border">
                <table className="w-full text-left text-sm">
                    <caption className="sr-only">{t('settings:dataExport.tableCaption')}</caption>
                    <thead className="border-b border-border bg-surface text-text-2">
                        <tr>
                            <th scope="col" className="px-4 py-2 font-medium">{t('settings:dataExport.columns.requested')}</th>
                            <th scope="col" className="px-4 py-2 font-medium">{t('settings:dataExport.columns.requestedBy')}</th>
                            <th scope="col" className="px-4 py-2 font-medium">{t('settings:dataExport.columns.status')}</th>
                            <th scope="col" className="px-4 py-2 font-medium">{t('settings:dataExport.columns.availableUntil')}</th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                <span className="sr-only">{t('settings:dataExport.columns.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {requests.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-4 py-6 text-center text-text-2">
                                    {t('settings:dataExport.empty')}
                                </td>
                            </tr>
                        )}

                        {requests.map((row) => (
                            <tr key={row.id} className="border-b border-border-soft last:border-0">
                                <td className="px-4 py-3 text-text">{formatDateTime(row.requestedAt, locale)}</td>
                                <td className="px-4 py-3 text-text-2">{row.requestedBy?.name ?? '—'}</td>
                                <td className="px-4 py-3">
                                    <StatusBadge tone={statusTone(row)}>{statusLabel(row, t)}</StatusBadge>
                                    {row.status === 'failed' && row.errorMessage && (
                                        <p className="mt-1 text-xs text-danger">{row.errorMessage}</p>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-text-2">
                                    {row.expiresAt ? formatDate(row.expiresAt, locale) : '—'}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    {row.canDownload && workspace && (
                                        // `<a href>`, nu `<Link>`: o vizită Inertia tratează
                                        // răspunsul binar (`Content-Disposition: attachment`)
                                        // ca excepție HTTP și deschide dialogul de eroare în
                                        // loc să descarce — defect real, documentat în
                                        // `Exports/Show.tsx`.
                                        <a
                                            href={`/${workspace.slug}/settings/data-export/${row.id}/download`}
                                            className={buttonClass('secondary')}
                                            aria-label={t('settings:dataExport.downloadAriaLabel', {
                                                date: formatDateTime(row.requestedAt, locale),
                                            })}
                                        >
                                            {t('settings:dataExport.downloadButton')}
                                        </a>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}

DataExportIndex.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
