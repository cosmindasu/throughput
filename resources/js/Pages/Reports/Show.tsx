import { router, Head, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button, { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import AppLayout from '@/Layouts/AppLayout';
import { formatDateTime, formatNumber } from '@/lib/format';
import type { ReportRunRow, ReportRunStatus, ReportsShowPageProps } from '@/types/generated';

const RUN_STATUS_TONES: Record<ReportRunStatus, BadgeTone> = {
    queued: 'neutral',
    running: 'accent',
    success: 'success',
    failed: 'danger',
};

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
    const { t } = useTranslation('reports');
    const locale = useLocale();
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
            onError: (formErrors) => setDeleteError(Object.values(formErrors)[0] ?? t('reports:show.deleteError')),
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
            {/* `report.name` e conținut scris de utilizator (FR-I18N-06) — niciodată tradus. */}
            <Head title={report.name} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={report.name}
                    description={
                        <span className="flex flex-wrap items-center gap-2">
                            {/* `report.sourceLabel` vine deja tradus din backend — nu se retraduce. */}
                            <span>{report.sourceLabel}</span>
                            <span>·</span>
                            {/* `report.format` (csv/xlsx/pdf) e un cod tehnic, identic în orice limbă. */}
                            <span className="uppercase">{report.format}</span>
                            <span>·</span>
                            <span>{t(`reports:frequency.${report.scheduleFrequency}`)}</span>
                            <StatusBadge tone={report.isActive ? 'success' : 'neutral'}>
                                {report.isActive ? t('reports:status.active') : t('reports:status.inactive')}
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
                                    {runningNow ? t('reports:actions.queuing') : t('reports:actions.runNow')}
                                </Button>
                            )}
                            {can.update && (
                                <ButtonLink href={`${base}/reports/${report.id}/edit`}>{t('reports:actions.edit')}</ButtonLink>
                            )}
                            {can.delete && (
                                <Button variant="danger" onClick={() => setConfirmingDelete(true)}>
                                    {t('reports:actions.delete')}
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
                        <StatusBadge tone={RUN_STATUS_TONES[latestRun.status]}>{t(`reports:runStatus.${latestRun.status}`)}</StatusBadge>
                        {inProgress && <span className="text-text-2">{t('reports:show.statusRegion.autoUpdating')}</span>}
                        {latestRun.status === 'success' && (
                            <span className="text-text-2">{t('reports:show.statusRegion.success')}</span>
                        )}
                        {latestRun.status === 'failed' && (
                            <span className="text-danger">
                                {latestRun.errorMessage ?? t('reports:show.statusRegion.failedFallback')}
                            </span>
                        )}
                    </div>
                )}

                <section className="flex flex-col gap-2 rounded-lg border border-border bg-surface p-4 text-sm">
                    <h2 className="font-medium text-text">{t('reports:show.recipientsHeading')}</h2>
                    <ul className="flex flex-wrap gap-2">
                        {/* Fiecare `email` e conținut scris de utilizator (FR-I18N-06) — netradus. */}
                        {report.recipients.map((email) => (
                            <li key={email} className="rounded-full bg-raised px-2 py-0.5 text-text-2">
                                {email}
                            </li>
                        ))}
                    </ul>
                </section>

                {builtInPreview && (
                    // `aria-labelledby`, nu `aria-label` duplicat: sursă unică de adevăr cu
                    // titlul `h2` chiar de dedesubt (găsit la scanare, în afara listei
                    // auditului — același principiu ca „Numele accesibil al unui tabel"
                    // din `.ai/rules/frontend.md`, aplicat aici landmark-ului).
                    <section aria-labelledby="current-result-heading" className="flex flex-col gap-3">
                        <h2 id="current-result-heading" className="text-sm font-medium text-text">
                            {t('reports:show.currentResult.heading')}
                            {!builtInPreview.hiddenForCost && (
                                <span className="ml-2 font-normal text-text-3">
                                    {/*
                                     * Pluralizare CLDR reală (`rowsCount_one/_many/_other`), nu text englez
                                     * invariant — franceza flexionează „ligne"/„lignes"; `count` alege
                                     * categoria, `formatted` e valoarea afișată prin `formatNumber` (separator
                                     * de mii locale-aware, FR-I18N-03). Parantezele stau ÎN AFARA cheilor —
                                     * punctuație universală, nu conținut de tradus.
                                     *
                                     * Al doilea contor (`rows.length`, „showing first N") flexionează separat
                                     * de cel total — cheie proprie (`shown_*`): un singur `count` per apel
                                     * `t()` nu poate purta două categorii CLDR simultan.
                                     */}
                                    {'('}
                                    {t('reports:show.currentResult.rowsCount', {
                                        count: builtInPreview.totalRows,
                                        formatted: formatNumber(builtInPreview.totalRows, locale),
                                    })}
                                    {builtInPreview.truncated && (
                                        <>
                                            {', '}
                                            {t('reports:show.currentResult.shown', {
                                                count: builtInPreview.rows.length,
                                                formatted: formatNumber(builtInPreview.rows.length, locale),
                                            })}
                                        </>
                                    )}
                                    {')'}
                                </span>
                            )}
                        </h2>

                        {builtInPreview.hiddenForCost ? (
                            <EmptyState message={t('reports:show.currentResult.hiddenForCost')} />
                        ) : builtInPreview.rows.length === 0 ? (
                            <EmptyState message={t('reports:show.currentResult.empty')} />
                        ) : (
                            <div className="overflow-x-auto rounded-lg border border-border">
                                <table className="data-table w-full text-left text-sm">
                                    <caption className="sr-only">{t('reports:show.currentResult.tableCaption', { name: report.name })}</caption>
                                    <thead className="bg-raised text-text-2">
                                        <tr>
                                            {/* Anteturile de coloană ale rapoartelor built-in vin deja traduse
                                                din backend (catalogul Laravel, Val 2) — nu se retraduc aici. */}
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
                                            <tr key={row.join('|')}>
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

                {/* `aria-labelledby`, nu `aria-label` duplicat — vezi comentariul de la
                    secțiunea „Current result" de mai sus. */}
                <section aria-labelledby="run-history-heading" className="flex flex-col gap-3">
                    <h2 id="run-history-heading" className="text-sm font-medium text-text">{t('reports:show.runHistory.heading')}</h2>

                    {runs.length === 0 ? (
                        <EmptyState message={t('reports:show.runHistory.empty')} />
                    ) : (
                        <div className="overflow-hidden rounded-lg border border-border">
                            <table className="data-table w-full text-left text-sm">
                                <caption className="sr-only">{t('reports:show.runHistory.tableCaption', { name: report.name })}</caption>
                                <thead className="bg-raised text-text-2">
                                    <tr>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('reports:show.runHistory.columns.status')}
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('reports:show.runHistory.columns.triggeredBy')}
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('reports:show.runHistory.columns.started')}
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('reports:show.runHistory.columns.rows')}
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            <span className="sr-only">{t('reports:show.runHistory.columns.download')}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border-soft bg-surface">
                                    {runs.map((run) => (
                                        <tr key={run.id}>
                                            <td className="px-4 py-2.5">
                                                <StatusBadge tone={RUN_STATUS_TONES[run.status]}>
                                                    {t(`reports:runStatus.${run.status}`)}
                                                </StatusBadge>
                                                {run.status === 'failed' && run.errorMessage && (
                                                    <p className="mt-1 text-xs text-danger">{run.errorMessage}</p>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5 text-text-2">{t(`reports:triggeredBy.${run.triggeredBy}`)}</td>
                                            <td className="numeric px-4 py-2.5 text-text-2">
                                                {run.startedAt ? formatDateTime(run.startedAt, locale) : '—'}
                                            </td>
                                            <td className="numeric px-4 py-2.5 text-text-2">
                                                {run.rowCount !== null ? formatNumber(run.rowCount, locale) : '—'}
                                            </td>
                                            <td className="px-4 py-2.5 text-right">
                                                {/* Descărcare binară — `<a href>` simplu, NU un `<Link>` Inertia: un
                                                    răspuns `Content-Disposition: attachment` fără antet `X-Inertia`
                                                    ar deschide dialogul de eroare al Inertia în loc să descarce
                                                    (defect real, documentat în `Exports/Show.tsx`). */}
                                                {can.download && run.hasFile && (
                                                    <a
                                                        href={`${base}/reports/${report.id}/runs/${run.id}/download`}
                                                        className="text-accent-text hover:underline"
                                                    >
                                                        {t('reports:show.runHistory.columns.download')}
                                                        {/* Audit de accesibilitate (SC 2.5.3) — sufix `sr-only`, nu
                                                            `aria-label`: „Download" rămâne conținut în numele
                                                            accesibil pe fiecare rând (fix P3 din audit). */}
                                                        <span className="sr-only">
                                                            {' '}
                                                            {t('reports:show.runHistory.downloadSrLabel', {
                                                                date: run.startedAt ? formatDateTime(run.startedAt, locale) : `#${run.id}`,
                                                            })}
                                                        </span>
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
                title={t('reports:show.deleteConfirmTitle', { name: report.name })}
                onConfirm={destroy}
                confirmVariant="danger"
                confirmLabel={t('reports:actions.delete')}
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
                {t('reports:show.deleteWarning')}
            </ConfirmDialog>
        </>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
