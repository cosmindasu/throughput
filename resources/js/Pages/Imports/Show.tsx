import { Head, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react';
import type { TFunction } from 'i18next';
import { Trans, useTranslation } from 'react-i18next';
import Button, { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/lib/format';
import { buildImportStatusLabels, IMPORT_STATUS_TONES } from '@/lib/importStatus';
import AppLayout from '@/Layouts/AppLayout';
import type { ImportMappingConfidence, ImportsShowPageProps, ImportStatus } from '@/types/generated';

const TERMINAL_STATUSES: ImportStatus[] = ['completed', 'completed_with_errors', 'failed'];
const RUNNING_STATUSES: ImportStatus[] = ['validating', 'importing'];

/**
 * Titlul de pas descrie POZIȚIA în flux („Step 3 of 4 — …"), distinct de eticheta scurtă a
 * chip-ului (`buildImportStatusLabels`, `@/lib/importStatus`) — cele două nu se amestecă:
 * chip-ul de status folosește ACUM eticheta scurtă, comună cu `Imports/Index` (altfel arăta
 * enum-ul brut, `completed_with_errors`, găsit la audit).
 *
 * `IMPORT_STATUS_TONES`/`buildImportStatusLabels` (`lib/importStatus.ts`) — partajate cu
 * `Imports/Index.tsx`. Fișierul a fost extins ulterior la lotul A3 (era `.ts`, în afara
 * partiționării inițiale pe `.tsx`, deci a rămas netradus la prima trecere); etichetele chip-
 * ului trec acum prin fabrica `buildImportStatusLabels(t)`, memoizată mai jos.
 *
 * `STEP_TITLES`/`CONFIDENCE_LABELS` de mai jos sunt fabrici parametrizate pe `t`, memoizate
 * în componentă (`.ai/rules/frontend.md` §6), pe tiparul din `Pages/Deals/Index.tsx`.
 */
const buildStepTitles = (t: TFunction): Record<ImportStatus, string> => ({
    uploaded: t('imports:show.stepTitles.uploaded'),
    mapped: t('imports:show.stepTitles.mapped'),
    validating: t('imports:show.stepTitles.validating'),
    validated: t('imports:show.stepTitles.validated'),
    importing: t('imports:show.stepTitles.importing'),
    completed: t('imports:show.stepTitles.completed'),
    completed_with_errors: t('imports:show.stepTitles.completed_with_errors'),
    failed: t('imports:show.stepTitles.failed'),
});

const CONFIDENCE_TONES: Record<ImportMappingConfidence, BadgeTone> = {
    high: 'success',
    medium: 'warning',
    low: 'warning',
    none: 'neutral',
};

const buildConfidenceLabels = (t: TFunction): Record<ImportMappingConfidence, string> => ({
    high: t('imports:show.confidence.high'),
    medium: t('imports:show.confidence.medium'),
    low: t('imports:show.confidence.low'),
    none: t('imports:show.confidence.none'),
});

/**
 * Imports/Show — cei 4 pași ca UN SINGUR ecran (§14.1), cu stări succesive pe `import.status`:
 * mapare (`uploaded`) → probă uscată (`mapped`/`validating`/`validated`) → commit
 * (`importing`) → raport final (`completed`/`completed_with_errors`/`failed`).
 *
 * Polling la 2s cât timp `validating`/`importing` (tiparul din `Bulk/Show.tsx`) — fiecare
 * chunk de fond (`RunDryRunValidationJob`/`CommitImportJob`, auto-continuare) actualizează
 * `total_rows`/`valid_rows`/`error_rows` într-o tranzacție scurtă proprie, deci progresul
 * chiar crește vizibil între poll-uri, nu doar la final.
 *
 * Focusul (`.ai/rules/frontend.md`, „Focusul nu se pierde niciodată pe body"): butonul
 * declanșator al fiecărui pas DISPARE odată ce statusul avansează (ex: „Run dry-run
 * validation" dispare la `validating`) — la fiecare schimbare de status, focusul se mută
 * explicit pe titlul pasului curent (`aria-live="polite"`, FĂRĂ `role="status"` — un `role`
 * explicit pe un `<h2>` îi suprascrie semantica nativă de titlu, scoțându-l din navigarea pe
 * titluri a cititoarelor de ecran; `aria-live` singur anunță schimbarea, fără să ceară asta),
 * un element STABIL care există în toate stările.
 *
 * `usePoll` are nevoie de `start()`, nu doar de `autoStart` (`.ai/rules/frontend.md`):
 * `autoStart` se evaluează o singură dată, la montare, când statusul e încă `uploaded`/
 * `mapped`. Pagina NU se remontează la „Run dry-run validation"/„Import N valid rows" (același
 * `import.status`, aceeași rută Inertia), deci fără `start()` simetric aici, polling-ul rămânea
 * oprit PERMANENT după prima tranziție — găsit la audit, tiparul corect e în
 * `Components/Orders/ShipmentsSection.tsx`.
 */
export default function Show() {
    const { import: importRow, fields, headers, mappingSuggestions, invalidRows, invalidRowsTruncated, templateUrl, errorReportUrl, can, workspace } =
        usePage<ImportsShowPageProps>().props;
    const { t } = useTranslation('imports');
    const locale = useLocale();
    const base = workspace ? `/${workspace.slug}` : '';

    const stepTitles = useMemo(() => buildStepTitles(t), [t]);
    const statusLabels = useMemo(() => buildImportStatusLabels(t), [t]);

    const isRunning = RUNNING_STATUSES.includes(importRow.status);
    const isTerminal = TERMINAL_STATUSES.includes(importRow.status);

    const { start, stop } = usePoll(2000, {}, { autoStart: isRunning });

    useEffect(() => {
        if (isRunning) {
            start();
        } else {
            stop();
        }
    }, [isRunning, start, stop]);

    const statusHeadingRef = useRef<HTMLHeadingElement>(null);
    const previousStatus = useRef(importRow.status);

    useEffect(() => {
        if (previousStatus.current === importRow.status) {
            return;
        }

        previousStatus.current = importRow.status;
        statusHeadingRef.current?.focus();
    }, [importRow.status]);

    const totalRows = importRow.totalRows ?? 0;
    const processedRows = (importRow.validRows ?? 0) + (importRow.errorRows ?? 0);
    const progressPercent = totalRows > 0 ? Math.min(100, Math.round((processedRows / totalRows) * 100)) : 0;

    return (
        <>
            <Head title={t('imports:show.headTitle', { filename: importRow.originalFilename })} />

            <div className="flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title={importRow.originalFilename}
                    description={t('imports:show.pageDescription', { resourceLabel: importRow.resourceLabel })}
                    actions={
                        <>
                            {can.cancel && !isTerminal && <CancelImportButton importId={importRow.id} base={base} />}
                            <ButtonLink href={`${base}/imports`}>{t('imports:show.backToImports')}</ButtonLink>
                        </>
                    }
                />

                <div className="flex items-center gap-3">
                    <h2
                        ref={statusHeadingRef}
                        tabIndex={-1}
                        aria-live="polite"
                        aria-atomic="true"
                        className="text-base font-semibold text-text outline-none"
                    >
                        {stepTitles[importRow.status]}
                    </h2>
                    <StatusBadge tone={IMPORT_STATUS_TONES[importRow.status]}>{statusLabels[importRow.status]}</StatusBadge>
                </div>

                {importRow.status === 'uploaded' && headers && mappingSuggestions && can.manage && (
                    <MappingStep headers={headers} suggestions={mappingSuggestions} fields={fields} importId={importRow.id} base={base} />
                )}

                {importRow.status === 'mapped' && (
                    <MappedStep importId={importRow.id} base={base} columnMapping={importRow.columnMapping} fields={fields} canManage={can.manage} />
                )}

                {isRunning && (
                    <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                        <div className="h-2 w-full overflow-hidden rounded-full bg-raised" aria-hidden="true">
                            <div className="h-full bg-accent-fill transition-[width]" style={{ width: `${progressPercent}%` }} />
                        </div>
                        <p className="numeric text-sm text-text-2">
                            {t('imports:show.progress.rowsProcessed', {
                                count: processedRows,
                                processed: formatNumber(processedRows, locale),
                                total: totalRows > 0 ? formatNumber(totalRows, locale) : '…',
                            })}
                            {importRow.errorRows
                                ? ` ${t('imports:show.progress.invalidSoFar', {
                                      count: importRow.errorRows,
                                      formattedCount: formatNumber(importRow.errorRows, locale),
                                  })}`
                                : ''}
                        </p>
                        <p className="text-sm text-text-2">{t('imports:show.progress.autoUpdate')}</p>
                    </div>
                )}

                {importRow.status === 'validated' && (
                    <DryRunReport importRow={importRow} invalidRows={invalidRows} invalidRowsTruncated={invalidRowsTruncated} errorReportUrl={errorReportUrl} base={base} canManage={can.manage} />
                )}

                {isTerminal && (
                    <FinalReport
                        importRow={importRow}
                        invalidRows={invalidRows}
                        invalidRowsTruncated={invalidRowsTruncated}
                        errorReportUrl={errorReportUrl}
                        base={base}
                    />
                )}

                {!headers && importRow.status === 'uploaded' && (
                    <p className="text-sm text-text-2">{t('imports:show.loadingColumns')}</p>
                )}

                <p className="text-xs text-text-3">
                    <Trans
                        t={t}
                        i18nKey="imports:show.templateHelp"
                        values={{ resourceLabel: importRow.resourceLabel }}
                        components={{ link: <a href={templateUrl} className="text-accent-text hover:underline" /> }}
                    />
                </p>
            </div>
        </>
    );
}

/**
 * P1 (review general) — calea PRINCIPALĂ de recuperare dintr-un import blocat/abandonat:
 * vizibil pentru orice status NE-terminal (§22.5, un singur import activ per tenant — fără
 * ea, un upload abandonat blochează tenantul la nesfârșit). `ConfirmDialog` (nativ,
 * `<dialog>`) gestionează deja corect capcana de focus și închiderea — vezi docblock-ul lui.
 */
function CancelImportButton({ importId, base }: { importId: string; base: string }) {
    const { t } = useTranslation('imports');
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const cancel = () => {
        setProcessing(true);

        router.post(
            `${base}/imports/${importId}/cancel`,
            {},
            {
                onSuccess: () => setOpen(false),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Button variant="danger" onClick={() => setOpen(true)}>
                {t('imports:show.cancelImport.trigger')}
            </Button>
            <ConfirmDialog
                open={open}
                title={t('imports:show.cancelImport.confirmTitle')}
                onConfirm={cancel}
                confirmLabel={t('imports:show.cancelImport.confirmLabel')}
                confirmVariant="danger"
                processing={processing}
                onClose={() => setOpen(false)}
            >
                {t('imports:show.cancelImport.body')}
            </ConfirmDialog>
        </>
    );
}

function MappingStep({
    headers,
    suggestions,
    fields,
    importId,
    base,
}: {
    headers: string[];
    suggestions: ImportsShowPageProps['mappingSuggestions'];
    fields: ImportsShowPageProps['fields'];
    importId: string;
    base: string;
}) {
    const { t } = useTranslation('imports');
    const confidenceLabels = useMemo(() => buildConfidenceLabels(t), [t]);

    const [mapping, setMapping] = useState<Record<string, string>>(() => {
        const initial: Record<string, string> = {};

        headers.forEach((header) => {
            const suggestion = suggestions?.find((item) => item.header === header);
            initial[header] = suggestion?.field ?? '';
        });

        return initial;
    });
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = () => {
        setProcessing(true);
        setError(null);

        router.post(
            `${base}/imports/${importId}/mapping`,
            { mapping },
            {
                onError: (errors) => setError(Object.values(errors)[0] ?? t('imports:show.mapping.genericError')),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
            <h3 className="text-sm font-semibold text-text">{t('imports:show.mapping.heading')}</h3>
            <p className="text-sm text-text-2">{t('imports:show.mapping.description')}</p>

            {error && (
                <p role="alert" className="text-sm text-danger">
                    {error}
                </p>
            )}

            <div className="overflow-hidden rounded-md border border-border">
                <table className="w-full text-left text-sm">
                    {/* Al treilea tipar de nume de tabel („niciunul") — unificat pe cazul
                        implicit din `.ai/rules/frontend.md`. Headingul de deasupra e al PASULUI
                        („Step 2 — Map columns"), nu al tabelului. */}
                    <caption className="sr-only">{t('imports:show.columnMappingLabel')}</caption>
                    <thead className="bg-raised text-text-2">
                        <tr>
                            <th scope="col" className="px-3 py-2 font-medium">{t('imports:show.mapping.columnFile')}</th>
                            <th scope="col" className="px-3 py-2 font-medium">{t('imports:show.mapping.columnMapsTo')}</th>
                            <th scope="col" className="px-3 py-2 font-medium">{t('imports:show.mapping.columnConfidence')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border-soft bg-surface">
                        {headers.map((header) => {
                            const suggestion = suggestions?.find((item) => item.header === header);
                            const fieldId = `mapping-${header}`;

                            return (
                                <tr key={header}>
                                    {/* `header` = antetul REAL din fișierul CSV al utilizatorului — conținut, nu
                                        interfață (FR-I18N-06). NU trece prin `t()`, nici aici, nici mai jos. */}
                                    <td className="px-3 py-2 font-medium text-text">{header}</td>
                                    <td className="px-3 py-2">
                                        <label htmlFor={fieldId} className="sr-only">
                                            {t('imports:show.mapping.columnForHeader', { header })}
                                        </label>
                                        <select
                                            id={fieldId}
                                            value={mapping[header] ?? ''}
                                            onChange={(event) => setMapping((prev) => ({ ...prev, [header]: event.target.value }))}
                                            className="rounded-md border border-control bg-surface px-2 py-1 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                                        >
                                            <option value="">{t('imports:show.mapping.noColumn')}</option>
                                            {fields.map((field) => (
                                                // `field.label` vine din backend (`ImportField->label`), NETRADUS
                                                // server-side — vezi raportul lotului. Nu se re-traduce aici.
                                                <option key={field.key} value={field.key}>
                                                    {field.label}
                                                    {field.required ? t('imports:show.mapping.required') : ''}
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                    <td className="px-3 py-2">
                                        {suggestion && suggestion.field && (
                                            <StatusBadge tone={CONFIDENCE_TONES[suggestion.confidence]}>
                                                {confidenceLabels[suggestion.confidence]}
                                            </StatusBadge>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <div>
                <Button
                    variant="primary"
                    aria-disabled={processing || undefined}
                    onClick={processing ? undefined : submit}
                    className={processing ? 'cursor-not-allowed opacity-60' : ''}
                >
                    {processing ? t('imports:show.mapping.saving') : t('imports:show.mapping.save')}
                </Button>
            </div>
        </div>
    );
}

function MappedStep({
    importId,
    base,
    columnMapping,
    fields,
    canManage,
}: {
    importId: string;
    base: string;
    columnMapping: ImportsShowPageProps['import']['columnMapping'];
    fields: ImportsShowPageProps['fields'];
    canManage: boolean;
}) {
    const { t } = useTranslation('imports');
    const [processing, setProcessing] = useState(false);

    const runDryRun = () => {
        setProcessing(true);
        router.post(`${base}/imports/${importId}/dry-run`, {}, { onFinish: () => setProcessing(false) });
    };

    // `field.label` (backend, netradus — vezi nota din `MappingStep`); fallback pe `key` dacă
    // maparea trimite un cod necunoscut.
    const fieldLabel = (key: string) => fields.find((field) => field.key === key)?.label ?? key;

    return (
        <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
            <h3 className="text-sm font-semibold text-text">{t('imports:show.columnMappingLabel')}</h3>
            <ul className="flex flex-col gap-1 text-sm text-text-2">
                {Object.entries(columnMapping ?? {})
                    .filter(([, field]) => field)
                    .map(([header, field]) => (
                        <li key={header}>
                            {/* `header` = antet CSV al utilizatorului — conținut, FR-I18N-06, nu se traduce. */}
                            <span className="text-text">{header}</span> → {fieldLabel(field as string)}
                        </li>
                    ))}
            </ul>

            {canManage && (
                <div>
                    <Button
                        variant="primary"
                        aria-disabled={processing || undefined}
                        onClick={processing ? undefined : runDryRun}
                        className={processing ? 'cursor-not-allowed opacity-60' : ''}
                    >
                        {processing ? t('imports:show.starting') : t('imports:show.mapped.runDryRun')}
                    </Button>
                </div>
            )}
        </div>
    );
}

function InvalidRowsTable({
    invalidRows,
    invalidRowsTruncated,
}: {
    invalidRows: ImportsShowPageProps['invalidRows'];
    invalidRowsTruncated: boolean;
}) {
    const { t } = useTranslation('imports');
    const locale = useLocale();
    const headingId = useId();

    if (!invalidRows || invalidRows.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2">
            <h4 id={headingId} className="text-sm font-semibold text-text">{t('imports:show.invalidRows.heading')}</h4>
            <div className="max-h-96 overflow-auto rounded-md border border-border">
                <table className="w-full text-left text-sm" aria-labelledby={headingId}>
                    <thead className="sticky top-0 bg-raised text-text-2">
                        <tr>
                            <th scope="col" className="px-3 py-2 font-medium">{t('imports:show.invalidRows.columnRow')}</th>
                            <th scope="col" className="px-3 py-2 font-medium">{t('imports:show.invalidRows.columnErrors')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border-soft bg-surface">
                        {invalidRows.map((row) => (
                            <tr key={row.rowNumber}>
                                <td className="px-3 py-2 numeric text-text">{row.rowNumber}</td>
                                <td className="px-3 py-2 text-text-2">
                                    {row.errors.map((error, index) => (
                                        // `error.message` vine din validarea Laravel (deja localizată server-side,
                                        // `lang/{en,fr}/validation.php`) — nu se re-traduce în frontend.
                                        <div key={index}>
                                            {error.field}: {error.message}
                                        </div>
                                    ))}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {invalidRowsTruncated && (
                <p className="text-xs text-text-3">
                    {t('imports:show.invalidRows.truncated', {
                        count: invalidRows.length,
                        formattedCount: formatNumber(invalidRows.length, locale),
                    })}
                </p>
            )}
        </div>
    );
}

function DryRunReport({
    importRow,
    invalidRows,
    invalidRowsTruncated,
    errorReportUrl,
    base,
    canManage,
}: {
    importRow: ImportsShowPageProps['import'];
    invalidRows: ImportsShowPageProps['invalidRows'];
    invalidRowsTruncated: boolean;
    errorReportUrl: string | null;
    base: string;
    canManage: boolean;
}) {
    const { t } = useTranslation('imports');
    const locale = useLocale();
    const [processing, setProcessing] = useState(false);

    const validRows = importRow.validRows ?? 0;
    const errorRows = importRow.errorRows ?? 0;
    const totalRows = importRow.totalRows ?? 0;

    const commit = () => {
        setProcessing(true);
        router.post(`${base}/imports/${importRow.id}/commit`, {}, { onFinish: () => setProcessing(false) });
    };

    return (
        <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
            <h3 className="text-sm font-semibold text-text">{t('imports:show.dryRun.heading')}</h3>
            <p className="numeric text-sm text-text-2">
                {t('imports:show.dryRun.summary', {
                    count: validRows,
                    valid: formatNumber(validRows, locale),
                    invalid: formatNumber(errorRows, locale),
                    total: formatNumber(totalRows, locale),
                })}
            </p>

            {errorReportUrl && (
                <a href={errorReportUrl} className="self-start text-sm text-accent-text hover:underline">
                    {t('imports:show.dryRun.downloadFailed', { count: errorRows, formattedCount: formatNumber(errorRows, locale) })}
                </a>
            )}

            <InvalidRowsTable invalidRows={invalidRows} invalidRowsTruncated={invalidRowsTruncated} />

            {canManage && validRows > 0 && (
                <div>
                    <Button
                        variant="primary"
                        aria-disabled={processing || undefined}
                        onClick={processing ? undefined : commit}
                        className={processing ? 'cursor-not-allowed opacity-60' : ''}
                    >
                        {processing
                            ? t('imports:show.starting')
                            : t('imports:show.dryRun.commit', { count: validRows, formattedCount: formatNumber(validRows, locale) })}
                    </Button>
                </div>
            )}
        </div>
    );
}

function FinalReport({
    importRow,
    invalidRows,
    invalidRowsTruncated,
    errorReportUrl,
    base,
}: {
    importRow: ImportsShowPageProps['import'];
    invalidRows: ImportsShowPageProps['invalidRows'];
    invalidRowsTruncated: boolean;
    errorReportUrl: string | null;
    base: string;
}) {
    const { t } = useTranslation('imports');
    const locale = useLocale();

    const validRows = importRow.validRows ?? 0;
    const errorRows = importRow.errorRows ?? 0;
    const totalRows = importRow.totalRows ?? 0;

    if (importRow.status === 'failed') {
        return (
            <div className="flex flex-col gap-3 rounded-lg border border-danger bg-danger-tint p-4">
                <p className="text-sm text-danger">{t('imports:show.final.failedMessage')}</p>
                <div>
                    <ButtonLink href={`${base}/imports/create`}>{t('imports:show.final.startNew')}</ButtonLink>
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
            <h3 className="text-sm font-semibold text-text">{t('imports:show.final.heading')}</h3>
            <p className="numeric text-sm text-text-2">
                {t('imports:show.final.summary', {
                    count: validRows,
                    valid: formatNumber(validRows, locale),
                    skipped: formatNumber(errorRows, locale),
                    total: formatNumber(totalRows, locale),
                })}
            </p>

            {errorReportUrl && (
                <a href={errorReportUrl} className="self-start text-sm text-accent-text hover:underline">
                    {t('imports:show.final.downloadSkipped', { count: errorRows, formattedCount: formatNumber(errorRows, locale) })}
                </a>
            )}

            <InvalidRowsTable invalidRows={invalidRows} invalidRowsTruncated={invalidRowsTruncated} />

            <div>
                <ButtonLink href={`${base}/imports/create`}>{t('imports:show.final.startAnother')}</ButtonLink>
            </div>
        </div>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
