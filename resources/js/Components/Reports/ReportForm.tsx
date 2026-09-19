import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Button from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import type { ReportBuiltInOption, ReportDefinitionRow, ReportFormat, ReportScheduleFrequency } from '@/types/generated';
import type { SavedViewSummary } from '@/types/generated';

interface ReportFormProps {
    mode: 'create' | 'edit';
    report?: ReportDefinitionRow;
    savedViews: SavedViewSummary[];
    builtInReports: ReportBuiltInOption[];
    action: string;
}

interface ReportFormData {
    name: string;
    report_type: string;
    saved_view_id: string;
    format: ReportFormat;
    schedule_frequency: ReportScheduleFrequency;
    schedule_time: string;
    schedule_day: string;
    recipients: string;
    is_active: boolean;
}

const WEEKDAYS = [
    { value: '1', label: 'Monday' },
    { value: '2', label: 'Tuesday' },
    { value: '3', label: 'Wednesday' },
    { value: '4', label: 'Thursday' },
    { value: '5', label: 'Friday' },
    { value: '6', label: 'Saturday' },
    { value: '7', label: 'Sunday' },
];

/**
 * Formular comun `Reports/Create` și `Reports/Edit` (specs.md §16.1/§16.4).
 *
 * Sursa (raport built-in sau vedere salvată) e alegerea de la CREARE, nu se schimbă la
 * editare — `App\Http\Requests\Reports\UpdateReportRequest` (docblock): un raport care își
 * schimbă sursa e conceptual un raport nou. În `mode="edit"`, secțiunea de sursă e afișată
 * needitabilă, dar valorile ei circulă tot prin `useForm` (necesare la `put()`).
 */
export default function ReportForm({ mode, report, savedViews, builtInReports, action }: ReportFormProps) {
    const { data, setData, post, put, processing, errors: typedErrors } = useForm<ReportFormData>({
        name: report?.name ?? '',
        report_type: report?.reportType ?? (builtInReports[0]?.value === undefined ? 'saved_view_export' : 'saved_view_export'),
        saved_view_id: report?.savedView?.id ?? '',
        format: report?.format ?? 'csv',
        schedule_frequency: report?.scheduleFrequency ?? 'none',
        schedule_time: report?.scheduleTime ?? '07:00',
        schedule_day: report?.scheduleDay !== null && report?.scheduleDay !== undefined ? String(report.scheduleDay) : '1',
        recipients: report?.recipients?.join('\n') ?? '',
        is_active: report?.isActive ?? true,
    });

    // `StoreReportRequest`/`UpdateReportRequest` valideaza `recipients.*` (fiecare adresă),
    // deci un email greșit din trei întoarce `recipients.0`, `recipients.1`… — chei absente
    // din `ReportFormData` (aici `recipients` e un singur `string`, textarea brută, nu un
    // array). Fix P1 (review): fără castul de mai jos, acele erori nu ajungeau NICIODATĂ pe
    // ecran — un 422 tăcut, pentru orice utilizator.
    const errors = typedErrors as Record<string, string | undefined>;
    const recipientErrors = Object.entries(errors)
        .filter(([key]) => key.startsWith('recipients.'))
        .map(([, message]) => message)
        .filter((message): message is string => Boolean(message));

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        // Butonul de submit e `aria-disabled`, NU `disabled` nativ, cât `processing` e
        // adevărat (fix P1, review — a treia apariție a defectului din
        // `.ai/rules/frontend.md`): rămâne focusabil, deci un al doilea submit (Enter
        // repetat, dublu clic) trebuie oprit AICI, nu de browser.
        if (processing) {
            return;
        }

        const recipients = data.recipients
            .split(/[\n,]/)
            .map((email) => email.trim())
            .filter((email) => email.length > 0);

        const payload = {
            ...data,
            recipients,
            saved_view_id: data.report_type === 'saved_view_export' ? data.saved_view_id : null,
            schedule_day:
                data.schedule_frequency === 'none' || data.schedule_frequency === 'daily' ? null : Number(data.schedule_day),
            schedule_time: data.schedule_frequency === 'none' ? null : data.schedule_time,
        };

        if (mode === 'create') {
            post(action, { data: payload } as never);
        } else {
            put(action, { data: payload } as never);
        }
    };

    const isSavedView = data.report_type === 'saved_view_export';
    const isInventoryValuation = data.report_type === 'inventory_valuation';

    return (
        <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Report name" error={errors.name} required>
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Format" error={errors.format} required>
                    {(control) => (
                        <select
                            {...control}
                            className={controlClass}
                            value={data.format}
                            onChange={(event) => setData('format', event.target.value as ReportFormat)}
                        >
                            <option value="csv">CSV</option>
                            <option value="xlsx">XLSX</option>
                            <option value="pdf">PDF</option>
                        </select>
                    )}
                </Field>
            </section>

            <fieldset className="flex flex-col gap-3 rounded-lg border border-border p-4">
                <legend className="px-1 text-sm font-medium text-text">Source</legend>

                {mode === 'create' ? (
                    <>
                        <label className="flex items-center gap-2 text-sm text-text">
                            <input
                                type="radio"
                                name="report_type"
                                checked={isSavedView}
                                onChange={() => setData('report_type', 'saved_view_export')}
                                className="accent-[var(--accent-fill)]"
                            />
                            Saved view export
                        </label>

                        {isSavedView && (
                            <Field label="Saved view" error={errors.saved_view_id} required>
                                {(control) => (
                                    <select
                                        {...control}
                                        className={controlClass}
                                        value={data.saved_view_id}
                                        onChange={(event) => setData('saved_view_id', event.target.value)}
                                    >
                                        <option value="">Select a saved view…</option>
                                        {savedViews.map((view) => (
                                            <option key={view.id} value={view.id}>
                                                {view.name} ({view.resourceType})
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </Field>
                        )}

                        {builtInReports.map((option) => (
                            <label key={option.value} className="flex items-center gap-2 text-sm text-text">
                                <input
                                    type="radio"
                                    name="report_type"
                                    checked={data.report_type === option.value}
                                    onChange={() => setData('report_type', option.value)}
                                    className="accent-[var(--accent-fill)]"
                                />
                                {option.label}
                            </label>
                        ))}
                    </>
                ) : (
                    <p className="text-sm text-text-2">
                        {report?.sourceLabel} — the source of a report can’t change after it’s created. Create a new report instead.
                    </p>
                )}

                {isInventoryValuation && (
                    <p role="note" className="text-xs text-warning">
                        This report includes stock cost/margin data, which is normally hidden from Agents and Viewers in the app.
                        Recipients receive the file regardless of role — choose them carefully.
                    </p>
                )}
            </fieldset>

            <fieldset className="flex flex-col gap-4 rounded-lg border border-border p-4">
                <legend className="px-1 text-sm font-medium text-text">Schedule</legend>

                <Field label="Frequency" error={errors.schedule_frequency} required>
                    {(control) => (
                        <select
                            {...control}
                            className={controlClass}
                            value={data.schedule_frequency}
                            onChange={(event) => setData('schedule_frequency', event.target.value as ReportScheduleFrequency)}
                        >
                            <option value="none">Manual only</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    )}
                </Field>

                {data.schedule_frequency !== 'none' && (
                    <Field
                        label="Time (UTC)"
                        error={errors.schedule_time}
                        required
                        hint="Scheduled reports run on the hour, compared in UTC."
                    >
                        {(control) => (
                            <input
                                {...control}
                                type="time"
                                className={controlClass}
                                value={data.schedule_time}
                                onChange={(event) => setData('schedule_time', event.target.value)}
                            />
                        )}
                    </Field>
                )}

                {data.schedule_frequency === 'weekly' && (
                    <Field label="Day of week" error={errors.schedule_day} required>
                        {(control) => (
                            <select
                                {...control}
                                className={controlClass}
                                value={data.schedule_day}
                                onChange={(event) => setData('schedule_day', event.target.value)}
                            >
                                {WEEKDAYS.map((day) => (
                                    <option key={day.value} value={day.value}>
                                        {day.label}
                                    </option>
                                ))}
                            </select>
                        )}
                    </Field>
                )}

                {data.schedule_frequency === 'monthly' && (
                    <Field
                        label="Day of month"
                        error={errors.schedule_day}
                        required
                        hint="A day beyond the end of a shorter month (e.g. 31) runs on that month’s last day instead."
                    >
                        {(control) => (
                            <input
                                {...control}
                                type="number"
                                min={1}
                                max={31}
                                className={controlClass}
                                value={data.schedule_day}
                                onChange={(event) => setData('schedule_day', event.target.value)}
                            />
                        )}
                    </Field>
                )}
            </fieldset>

            <Field
                label="Recipients"
                error={[errors.recipients, ...recipientErrors].filter(Boolean).join(' ') || undefined}
                required
                hint="One email address per line (or comma-separated)."
            >
                {(control) => (
                    <textarea
                        {...control}
                        className={controlClass}
                        rows={3}
                        value={data.recipients}
                        onChange={(event) => setData('recipients', event.target.value)}
                    />
                )}
            </Field>

            <label className="flex items-center gap-2 text-sm text-text">
                <input
                    type="checkbox"
                    checked={data.is_active}
                    onChange={(event) => setData('is_active', event.target.checked)}
                    className="h-4 w-4 accent-[var(--accent-fill)]"
                />
                Active
            </label>

            <div className="flex justify-end gap-2">
                <Button
                    type="submit"
                    variant="primary"
                    aria-disabled={processing || undefined}
                    className={processing ? 'cursor-not-allowed opacity-60' : ''}
                >
                    {processing ? 'Saving…' : mode === 'create' ? 'Create report' : 'Save changes'}
                </Button>
            </div>
        </form>
    );
}
