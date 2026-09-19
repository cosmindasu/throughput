import { Head, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import Button, { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import { IMPORT_STATUS_LABELS, IMPORT_STATUS_TONES } from '@/lib/importStatus';
import AppLayout from '@/Layouts/AppLayout';
import type { ImportMappingConfidence, ImportsShowPageProps, ImportStatus } from '@/types/generated';

const TERMINAL_STATUSES: ImportStatus[] = ['completed', 'completed_with_errors', 'failed'];
const RUNNING_STATUSES: ImportStatus[] = ['validating', 'importing'];

/**
 * Titlul de pas descrie POZIȚIA în flux („Step 3 of 4 — …"), distinct de eticheta scurtă a
 * chip-ului (`IMPORT_STATUS_LABELS`, `@/lib/importStatus`) — cele două nu se amestecă: chip-ul
 * de status folosește ACUM eticheta scurtă, comună cu `Imports/Index` (altfel arăta enum-ul
 * brut, `completed_with_errors`, găsit la audit).
 */
const STEP_TITLES: Record<ImportStatus, string> = {
    uploaded: 'Step 1 of 4 — Upload done',
    mapped: 'Step 2 of 4 — Columns mapped',
    validating: 'Step 3 of 4 — Validating…',
    validated: 'Step 3 of 4 — Dry run complete',
    importing: 'Step 4 of 4 — Importing…',
    completed: 'Step 4 of 4 — Completed',
    completed_with_errors: 'Step 4 of 4 — Completed with errors',
    failed: 'Failed',
};

const CONFIDENCE_TONES: Record<ImportMappingConfidence, BadgeTone> = {
    high: 'success',
    medium: 'warning',
    low: 'warning',
    none: 'neutral',
};

const CONFIDENCE_LABELS: Record<ImportMappingConfidence, string> = {
    high: 'High confidence',
    medium: 'Medium confidence',
    low: 'Low confidence',
    none: 'No match',
};

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
    const base = workspace ? `/${workspace.slug}` : '';

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
            <Head title={`Import — ${importRow.originalFilename}`} />

            <div className="flex max-w-3xl flex-col gap-6">
                <PageHeader
                    title={importRow.originalFilename}
                    description={`${importRow.resourceLabel} import`}
                    actions={
                        <>
                            {can.cancel && !isTerminal && <CancelImportButton importId={importRow.id} base={base} />}
                            <ButtonLink href={`${base}/imports`}>Back to imports</ButtonLink>
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
                        {STEP_TITLES[importRow.status]}
                    </h2>
                    <StatusBadge tone={IMPORT_STATUS_TONES[importRow.status]}>{IMPORT_STATUS_LABELS[importRow.status]}</StatusBadge>
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
                            {processedRows.toLocaleString('en-US')} / {totalRows > 0 ? totalRows.toLocaleString('en-US') : '…'} rows processed
                            {importRow.errorRows ? ` (${importRow.errorRows.toLocaleString('en-US')} invalid so far)` : ''}
                        </p>
                        <p className="text-sm text-text-2">This page updates automatically.</p>
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
                    <p className="text-sm text-text-2">Loading the file's columns…</p>
                )}

                <p className="text-xs text-text-3">
                    Need the expected columns? <a href={templateUrl} className="text-accent-text hover:underline">Download the template</a> for {importRow.resourceLabel}.
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
                Cancel import
            </Button>
            <ConfirmDialog
                open={open}
                title="Cancel this import?"
                onConfirm={cancel}
                confirmLabel="Cancel import"
                confirmVariant="danger"
                processing={processing}
                onClose={() => setOpen(false)}
            >
                Rows already imported keep their change. This cannot be undone, and you'll need to start a new import afterwards.
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
                onError: (errors) => setError(Object.values(errors)[0] ?? 'Could not save the mapping.'),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
            <h3 className="text-sm font-semibold text-text">Step 2 — Map columns</h3>
            <p className="text-sm text-text-2">
                We matched columns automatically where possible. Review the mapping below and adjust anything that looks wrong before running the
                dry run.
            </p>

            {error && (
                <p role="alert" className="text-sm text-danger">
                    {error}
                </p>
            )}

            <div className="overflow-hidden rounded-md border border-border">
                <table className="w-full text-left text-sm">
                    <thead className="bg-raised text-text-2">
                        <tr>
                            <th scope="col" className="px-3 py-2 font-medium">File column</th>
                            <th scope="col" className="px-3 py-2 font-medium">Maps to</th>
                            <th scope="col" className="px-3 py-2 font-medium">Confidence</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border-soft bg-surface">
                        {headers.map((header) => {
                            const suggestion = suggestions?.find((item) => item.header === header);
                            const fieldId = `mapping-${header}`;

                            return (
                                <tr key={header}>
                                    <td className="px-3 py-2 font-medium text-text">{header}</td>
                                    <td className="px-3 py-2">
                                        <label htmlFor={fieldId} className="sr-only">
                                            Column for {header}
                                        </label>
                                        <select
                                            id={fieldId}
                                            value={mapping[header] ?? ''}
                                            onChange={(event) => setMapping((prev) => ({ ...prev, [header]: event.target.value }))}
                                            className="rounded-md border border-control bg-surface px-2 py-1 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                                        >
                                            <option value="">Don't import this column</option>
                                            {fields.map((field) => (
                                                <option key={field.key} value={field.key}>
                                                    {field.label}
                                                    {field.required ? ' (required)' : ''}
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                    <td className="px-3 py-2">
                                        {suggestion && suggestion.field && (
                                            <StatusBadge tone={CONFIDENCE_TONES[suggestion.confidence]}>
                                                {CONFIDENCE_LABELS[suggestion.confidence]}
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
                    {processing ? 'Saving…' : 'Save mapping and continue'}
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
    const [processing, setProcessing] = useState(false);

    const runDryRun = () => {
        setProcessing(true);
        router.post(`${base}/imports/${importId}/dry-run`, {}, { onFinish: () => setProcessing(false) });
    };

    const fieldLabel = (key: string) => fields.find((field) => field.key === key)?.label ?? key;

    return (
        <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
            <h3 className="text-sm font-semibold text-text">Column mapping</h3>
            <ul className="flex flex-col gap-1 text-sm text-text-2">
                {Object.entries(columnMapping ?? {})
                    .filter(([, field]) => field)
                    .map(([header, field]) => (
                        <li key={header}>
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
                        {processing ? 'Starting…' : 'Run dry-run validation'}
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
    const headingId = useId();

    if (!invalidRows || invalidRows.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2">
            <h4 id={headingId} className="text-sm font-semibold text-text">Rows that need attention</h4>
            <div className="max-h-96 overflow-auto rounded-md border border-border">
                <table className="w-full text-left text-sm" aria-labelledby={headingId}>
                    <thead className="sticky top-0 bg-raised text-text-2">
                        <tr>
                            <th scope="col" className="px-3 py-2 font-medium">Row</th>
                            <th scope="col" className="px-3 py-2 font-medium">Errors</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border-soft bg-surface">
                        {invalidRows.map((row) => (
                            <tr key={row.rowNumber}>
                                <td className="px-3 py-2 numeric text-text">{row.rowNumber}</td>
                                <td className="px-3 py-2 text-text-2">
                                    {row.errors.map((error, index) => (
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
                <p className="text-xs text-text-3">Showing the first {invalidRows.length} invalid rows — download the error report for all of them.</p>
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
    const [processing, setProcessing] = useState(false);

    const commit = () => {
        setProcessing(true);
        router.post(`${base}/imports/${importRow.id}/commit`, {}, { onFinish: () => setProcessing(false) });
    };

    return (
        <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
            <h3 className="text-sm font-semibold text-text">Dry-run results</h3>
            <p className="numeric text-sm text-text-2">
                {(importRow.validRows ?? 0).toLocaleString('en-US')} rows ready to import, {(importRow.errorRows ?? 0).toLocaleString('en-US')} invalid,
                out of {(importRow.totalRows ?? 0).toLocaleString('en-US')} total.
            </p>

            {errorReportUrl && (
                <a href={errorReportUrl} className="self-start text-sm text-accent-text hover:underline">
                    Download the {(importRow.errorRows ?? 0).toLocaleString('en-US')} failed rows as CSV
                </a>
            )}

            <InvalidRowsTable invalidRows={invalidRows} invalidRowsTruncated={invalidRowsTruncated} />

            {canManage && (importRow.validRows ?? 0) > 0 && (
                <div>
                    <Button
                        variant="primary"
                        aria-disabled={processing || undefined}
                        onClick={processing ? undefined : commit}
                        className={processing ? 'cursor-not-allowed opacity-60' : ''}
                    >
                        {processing ? 'Starting…' : `Import ${(importRow.validRows ?? 0).toLocaleString('en-US')} valid rows`}
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
    if (importRow.status === 'failed') {
        return (
            <div className="flex flex-col gap-3 rounded-lg border border-danger bg-danger-tint p-4">
                <p className="text-sm text-danger">This import failed and could not finish. Start a new import once the issue is resolved.</p>
                <div>
                    <ButtonLink href={`${base}/imports/create`}>Start a new import</ButtonLink>
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
            <h3 className="text-sm font-semibold text-text">Final report</h3>
            <p className="numeric text-sm text-text-2">
                {(importRow.validRows ?? 0).toLocaleString('en-US')} rows imported, {(importRow.errorRows ?? 0).toLocaleString('en-US')} skipped,
                out of {(importRow.totalRows ?? 0).toLocaleString('en-US')} total.
            </p>

            {errorReportUrl && (
                <a href={errorReportUrl} className="self-start text-sm text-accent-text hover:underline">
                    Download the {(importRow.errorRows ?? 0).toLocaleString('en-US')} skipped rows as CSV — correct them and re-import
                </a>
            )}

            <InvalidRowsTable invalidRows={invalidRows} invalidRowsTruncated={invalidRowsTruncated} />

            <div>
                <ButtonLink href={`${base}/imports/create`}>Start another import</ButtonLink>
            </div>
        </div>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
