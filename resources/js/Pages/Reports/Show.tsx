import { router, Head, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import Button, { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { ReportRunRow, ReportRunStatus, ReportsShowPageProps } from '@/types/generated';

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

const dateTimeFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short' });

function isInProgress(run: ReportRunRow | null): boolean {
    return run !== null && (run.status === 'queued' || run.status === 'running');
}

/**
 * Reports/Show — detaliul unui raport (specs.md §16): „Run now", rezultatul built-in
 * randat sincron în pagină (US-REP-02), istoricul de rulări cu descărcare (FR-REP-01).
 *
 * Polling la 3s CÂT TIMP cea mai recentă rulare e `queued`/`running` — la fel ca
 * `Exports/Show.tsx` (Faza 2 n-are WebSockets, specs.md §3). Se oprește singur la starea
 * terminală.
 *
 * Fix P1 (review) — `usePoll` pornește polling-ul într-un `useEffect` cu dependențe GOALE
 * (`@inertiajs/react`): `autoStart` se evaluează O SINGURĂ DATĂ, la montare. Un raport FĂRĂ
 * rulare activă la deschidere are `inProgress = false`, deci `autoStart: false` îngheța
 * polling-ul PERMANENT — iar după „Run now" serverul redirecționează spre ACEEAȘI rută
 * (`reports.show`), deci componenta nu se remontează și `autoStart` nu se reevaluează
 * niciodată. Pagina nu se actualiza NICIODATĂ singură, exact contrar mesajului flash „this
 * page will update automatically". Fix: ramura `start()`, simetrică cu `stop()`
 * (`.ai/rules/frontend.md`, „usePoll are nevoie de start(), nu doar de autoStart").
 */
export default function Show() {
    const { report, runs, builtInPreview, can, workspace } = usePage<ReportsShowPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    // Ordonate `id DESC` de server (`ReportController::show()`) — `runs[0]` e cea mai
    // recentă. Regiunea de status de mai jos reflectă mereu STAREA ACTUALĂ a acestei
    // rulări, inclusiv starea terminală (fix P1 — regiunea veche anunța doar începutul,
    // niciodată succesul/eșecul).
    const latestRun = runs[0] ?? null;
    const inProgress = isInProgress(latestRun);

    const { start, stop } = usePoll(3000, {}, { autoStart: inProgress });

    useEffect(() => {
        if (inProgress) {
            start();
        } else {
            stop();
        }
    }, [inProgress, start, stop]);

    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);
    const [runningNow, setRunningNow] = useState(false);
    // Fix P1 (review) — banner VIZIBIL, nu `sr-only`: un utilizator de tastatură FĂRĂ
    // cititor de ecran trebuie să VADĂ unde a ajuns focusul, nu doar să-l aibă anunțat.
    // Tiparul e cel din `Settings/Members/Index.tsx` (`outcome`/`statusRef`).
    const statusRef = useRef<HTMLDivElement>(null);

    const destroy = () => {
        setDeleting(true);
        setDeleteError(null);
        router.delete(`${base}/reports/${report.id}`, {
            onSuccess: () => setConfirmingDelete(false),
            onError: (formErrors) => setDeleteError(Object.values(formErrors)[0] ?? 'This report could not be deleted.'),
            onFinish: () => setDeleting(false),
        });
    };

    const runNow = () => {
        // Butonul e `aria-disabled`, NU `disabled` nativ, cât `runningNow` e adevărat
        // (rămâne focusabil) — un al doilea clic/Enter trebuie oprit AICI.
        if (runningNow) {
            return;
        }

        setRunningNow(true);
        router.post(
            `${base}/reports/${report.id}/run`,
            {},
            {
                onSuccess: () => {
                    // Declanșatorul rămâne pe pagină, dar starea se schimbă sub el — mutăm
                    // focusul explicit pe regiunea de status (vizibilă), care se va
                    // actualiza singură prin polling pe măsură ce rularea avansează.
                    statusRef.current?.focus();
                },
                onFinish: () => setRunningNow(false),
            },
        );
    };

    return (
        <>
            <Head title={report.name} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={report.name}
                    description={
                        <span className="flex flex-wrap items-center gap-2">
                            <span>{report.sourceLabel}</span>
                            <span>·</span>
                            <span className="uppercase">{report.format}</span>
                            <span>·</span>
                            <span>{FREQUENCY_LABELS[report.scheduleFrequency]}</span>
                            <StatusBadge tone={report.isActive ? 'success' : 'neutral'}>
                                {report.isActive ? 'Active' : 'Inactive'}
                            </StatusBadge>
                        </span>
                    }
                    actions={
                        <>
                            {can.runNow && (
                                <Button
                                    variant="primary"
                                    aria-disabled={runningNow || undefined}
                                    onClick={runNow}
                                    className={runningNow ? 'cursor-not-allowed opacity-60' : ''}
                                >
                                    {runningNow ? 'Queuing…' : 'Run now'}
                                </Button>
                            )}
                            {can.update && <ButtonLink href={`${base}/reports/${report.id}/edit`}>Edit</ButtonLink>}
                            {can.delete && (
                                <Button variant="danger" onClick={() => setConfirmingDelete(true)}>
                                    Delete
                                </Button>
                            )}
                        </>
                    }
                />

                {latestRun && (
                    <div
                        ref={statusRef}
                        tabIndex={-1}
                        role="status"
                        aria-live="polite"
                        className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-surface p-4 text-sm focus:outline-none"
                    >
                        <StatusBadge tone={RUN_STATUS_TONES[latestRun.status]}>{RUN_STATUS_LABELS[latestRun.status]}</StatusBadge>
                        {inProgress && <span className="text-text-2">This page updates automatically.</span>}
                        {latestRun.status === 'success' && (
                            <span className="text-text-2">The report was generated — see it in the history below.</span>
                        )}
                        {latestRun.status === 'failed' && (
                            <span className="text-danger">
                                {latestRun.errorMessage ?? 'The report could not be generated.'}
                            </span>
                        )}
                    </div>
                )}

                <section className="flex flex-col gap-2 rounded-lg border border-border bg-surface p-4 text-sm">
                    <h2 className="font-medium text-text">Recipients</h2>
                    <ul className="flex flex-wrap gap-2">
                        {report.recipients.map((email) => (
                            <li key={email} className="rounded-full bg-raised px-2 py-0.5 text-text-2">
                                {email}
                            </li>
                        ))}
                    </ul>
                </section>

                {builtInPreview && (
                    <section aria-label="Current result" className="flex flex-col gap-3">
                        <h2 className="text-sm font-medium text-text">
                            Current result
                            {!builtInPreview.hiddenForCost && (
                                <span className="ml-2 font-normal text-text-3">
                                    ({builtInPreview.totalRows.toLocaleString('en-US')} rows
                                    {builtInPreview.truncated ? `, showing first ${builtInPreview.rows.length}` : ''})
                                </span>
                            )}
                        </h2>

                        {builtInPreview.hiddenForCost ? (
                            <EmptyState message="This report includes stock cost/margin data that your role can’t view. An Owner or Manager can see it here; you can still download the file from the history below if you’re a recipient." />
                        ) : builtInPreview.rows.length === 0 ? (
                            <EmptyState message="No data yet." />
                        ) : (
                            <div className="overflow-x-auto rounded-lg border border-border">
                                <table className="w-full text-left text-sm">
                                    <caption className="sr-only">Current result for {report.name}</caption>
                                    <thead className="bg-raised text-text-2">
                                        <tr>
                                            {builtInPreview.columns.map((column) => (
                                                <th key={column} scope="col" className="px-4 py-2 font-medium">
                                                    {column}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border-soft bg-surface">
                                        {builtInPreview.rows.map((row) => (
                                            // Rândurile agregate n-au id propriu — cheia e conținutul lor
                                            // (stabil între randări, unic per combinație pipeline/etapă
                                            // sau locație/categorie).
                                            <tr key={row.join('|')} className="hover:bg-row-hover">
                                                {row.map((cell, cellIndex) => (
                                                    <td
                                                        key={cellIndex}
                                                        className={`px-4 py-2.5 ${
                                                            typeof cell === 'number' ? 'numeric text-text-2' : 'text-text-2'
                                                        }`}
                                                    >
                                                        {cell ?? '—'}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                )}

                <section aria-label="Run history" className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">Run history</h2>

                    {runs.length === 0 ? (
                        <EmptyState message="This report hasn’t run yet." />
                    ) : (
                        <div className="overflow-hidden rounded-lg border border-border">
                            <table className="w-full text-left text-sm">
                                <caption className="sr-only">Run history for {report.name}</caption>
                                <thead className="bg-raised text-text-2">
                                    <tr>
                                        <th scope="col" className="px-4 py-2 font-medium">Status</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Triggered by</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Started</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Rows</th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            <span className="sr-only">Download</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border-soft bg-surface">
                                    {runs.map((run) => (
                                        <tr key={run.id} className="hover:bg-row-hover">
                                            <td className="px-4 py-2.5">
                                                <StatusBadge tone={RUN_STATUS_TONES[run.status]}>
                                                    {RUN_STATUS_LABELS[run.status]}
                                                </StatusBadge>
                                                {run.status === 'failed' && run.errorMessage && (
                                                    <p className="mt-1 text-xs text-danger">{run.errorMessage}</p>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5 text-text-2">{run.triggeredBy}</td>
                                            <td className="numeric px-4 py-2.5 text-text-2">
                                                {run.startedAt ? dateTimeFormatter.format(new Date(run.startedAt)) : '—'}
                                            </td>
                                            <td className="numeric px-4 py-2.5 text-text-2">
                                                {run.rowCount !== null ? run.rowCount.toLocaleString('en-US') : '—'}
                                            </td>
                                            <td className="px-4 py-2.5 text-right">
                                                {/* Descărcare binară — `<a href>` simplu, NU un `<Link>` Inertia: un
                                                    răspuns `Content-Disposition: attachment` fără antet `X-Inertia`
                                                    ar deschide dialogul de eroare al Inertia în loc să descarce
                                                    (defect real, documentat în `Exports/Show.tsx`). `aria-label`
                                                    disambiguizează „Download" repetat pe fiecare rând (fix P3). */}
                                                {can.download && run.hasFile && (
                                                    <a
                                                        href={`${base}/reports/${report.id}/runs/${run.id}/download`}
                                                        aria-label={`Download the run from ${
                                                            run.startedAt ? dateTimeFormatter.format(new Date(run.startedAt)) : `#${run.id}`
                                                        }`}
                                                        className="text-accent-text hover:underline"
                                                    >
                                                        Download
                                                    </a>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>

            <ConfirmDialog
                open={confirmingDelete}
                title={`Delete ${report.name}?`}
                onConfirm={destroy}
                confirmVariant="danger"
                confirmLabel="Delete"
                processing={deleting}
                onClose={() => {
                    setConfirmingDelete(false);
                    setDeleteError(null);
                }}
            >
                {deleteError && (
                    <p role="alert" className="mb-2 rounded-md bg-danger-tint px-2 py-1.5 text-danger">
                        {deleteError}
                    </p>
                )}
                This action cannot be undone. Scheduled delivery will stop immediately.
            </ConfirmDialog>
        </>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
