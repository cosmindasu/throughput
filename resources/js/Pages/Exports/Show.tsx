import { Head, usePage, usePoll } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';
import { buttonClass } from '@/Components/Button';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { ExportsShowPageProps, ExportStatus } from '@/types/generated';

const TERMINAL_STATUSES: ExportStatus[] = ['completed', 'failed', 'cancelled'];

// La fel ca Deals/Show.tsx — o singură dată la nivel de modul, nu recreat la fiecare randare.
const dateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' });

const TONES: Record<ExportStatus, BadgeTone> = {
    pending: 'neutral',
    running: 'accent',
    completed: 'success',
    failed: 'danger',
    cancelled: 'neutral',
};

const LABELS: Record<ExportStatus, string> = {
    pending: 'Queued',
    running: 'Running',
    completed: 'Ready to download',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

/**
 * Status-ul unui export în coadă (§13.2). Polling la 2 secunde cât operația e
 * `pending`/`running` — Faza 2 nu are WebSockets (specs.md §3, „fără Meilisearch, fără
 * Reverb"). Se oprește singur la starea terminală, ca fila deschisă după download să nu
 * mai bată serverul la nesfârșit.
 */
export default function Show() {
    const { export: exportStatus, workspace } = usePage<ExportsShowPageProps>().props;
    const isTerminal = TERMINAL_STATUSES.includes(exportStatus.status);

    const { stop } = usePoll(2000, {}, { autoStart: !isTerminal });

    useEffect(() => {
        if (isTerminal) {
            stop();
        }
    }, [isTerminal, stop]);

    return (
        <>
            <Head title="Export" />

            <div className="flex max-w-xl flex-col gap-6">
                <PageHeader title="Export" description={`${exportStatus.totalRows.toLocaleString('en-US')} rows`} />

                <div
                    role="status"
                    aria-live="polite"
                    className="flex items-center gap-3 rounded-lg border border-border bg-surface p-4"
                >
                    <StatusBadge tone={TONES[exportStatus.status]}>{LABELS[exportStatus.status]}</StatusBadge>
                    {!isTerminal && <span className="text-sm text-text-2">This page updates automatically.</span>}
                    {exportStatus.status === 'failed' && (
                        <span className="text-sm text-danger">The export could not be completed. Try again from the list.</span>
                    )}
                    {exportStatus.status === 'completed' && exportStatus.expiresAt && (
                        <span className="text-sm text-text-2">
                            {exportStatus.isExpired
                                ? `This export expired on ${dateFormatter.format(new Date(exportStatus.expiresAt))}.`
                                : `Available for download until ${dateFormatter.format(new Date(exportStatus.expiresAt))}.`}
                        </span>
                    )}
                </div>

                {exportStatus.canDownload && workspace && (
                    <div>
                        {/* Defect real găsit la auditul E2E (raportul pachetului) — un `<Link>`
                            Inertia (`ButtonLink`) intercepta acest click și trata răspunsul
                            binar (`Content-Disposition: attachment`, fără antet `X-Inertia`)
                            ca o excepție HTTP neașteptată (`handleNonInertiaResponse()` din
                            `@inertiajs/core`): dialogul de eroare al Inertia se deschidea în
                            loc să se declanșeze descărcarea. `<a href>` simplu, EXACT ca
                            exportul sincron din `Orders/Index.tsx` — o navigare reală de
                            browser, nu o vizită Inertia. */}
                        <a href={`/${workspace.slug}/exports/${exportStatus.id}/download`} className={buttonClass('primary')}>
                            Download {exportStatus.format.toUpperCase()}
                        </a>
                    </div>
                )}
            </div>
        </>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
