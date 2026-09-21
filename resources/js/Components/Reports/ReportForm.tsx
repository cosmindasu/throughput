import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
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

const WEEKDAY_VALUES = [
    { value: '1', key: 'monday' },
    { value: '2', key: 'tuesday' },
    { value: '3', key: 'wednesday' },
    { value: '4', key: 'thursday' },
    { value: '5', key: 'friday' },
    { value: '6', key: 'saturday' },
    { value: '7', key: 'sunday' },
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
    const { t } = useTranslation('reports');
    const { data, setData, post, put, transform, processing, errors: typedErrors } = useForm<ReportFormData>({
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

        // `transform()`, NU `post(action, { data: payload })` — găsit de suita E2E, Faza 4.
        // `useForm().submit()` IGNORĂ `options.data`: trimite întotdeauna starea internă a
        // hook-ului, netransformată. Consecința era că `recipients` pleca spre server ca
        // string brut în loc de listă, iar `StoreReportRequest` refuza cu „The recipients
        // field must be an array." — adică NICIUN raport nu putea fi creat sau editat din
        // interfață, deși toate testele de server treceau. Varianta greșită fusese tăcută cu
        // `as never`: tipurile semnalaseră corect problema, iar cast-ul le-a redus la tăcere.
        transform(() => payload);

        if (mode === 'create') {
            post(action);
        } else {
            put(action);
        }
    };

    const isSavedView = data.report_type === 'saved_view_export';
    const isInventoryValuation = data.report_type === 'inventory_valuation';

    return (
        <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label={t('reports:form.name.label')} error={errors.name} required>
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                        />
                    )}
                </Field>

                {/* CSV/XLSX/PDF — coduri de format tehnice, identice în orice limbă (ca „SKU"). */}
                <Field label={t('reports:form.format.label')} error={errors.format} required>
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
                <legend className="px-1 text-sm font-medium text-text">{t('reports:form.sourceLegend')}</legend>

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
                            {t('reports:form.savedViewExport')}
                        </label>

                        {isSavedView && (
                            <Field label={t('reports:form.savedView.label')} error={errors.saved_view_id} required>
                                {(control) => (
                                    <select
                                        {...control}
                                        className={controlClass}
                                        value={data.saved_view_id}
                                        onChange={(event) => setData('saved_view_id', event.target.value)}
                                    >
                                        <option value="">{t('reports:form.savedView.placeholder')}</option>
                                        {/* `view.name` e conținut scris de utilizator (FR-I18N-06) — netradus.
                                            `view.resourceType` (ex. „deals") e o valoare tehnică partajată cu
                                            alte loturi (SavedViewResourceType) — neatinsă aici, în afara scopului. */}
                                        {savedViews.map((view) => (
                                            <option key={view.id} value={view.id}>
                                                {view.name} ({view.resourceType})
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </Field>
                        )}

                        {/* `option.label` (titlurile rapoartelor built-in, ex. „Deal Velocity by
                            Stage") vine deja tradus din backend (catalogul Laravel, Val 2) — nu se
                            retraduce aici. */}
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
                        {/* `report.sourceLabel` vine deja tradus din backend — nu se retraduce. */}
                        {report?.sourceLabel} {t('reports:form.sourceLocked')}
                    </p>
                )}

                {isInventoryValuation && <p role="note" className="text-xs text-warning">{t('reports:form.inventoryValuationNote')}</p>}
            </fieldset>

            <fieldset className="flex flex-col gap-4 rounded-lg border border-border p-4">
                <legend className="px-1 text-sm font-medium text-text">{t('reports:form.scheduleLegend')}</legend>

                <Field label={t('reports:form.frequency.label')} error={errors.schedule_frequency} required>
                    {(control) => (
                        <select
                            {...control}
                            className={controlClass}
                            value={data.schedule_frequency}
                            onChange={(event) => setData('schedule_frequency', event.target.value as ReportScheduleFrequency)}
                        >
                            <option value="none">{t('reports:frequency.none')}</option>
                            <option value="daily">{t('reports:frequency.daily')}</option>
                            <option value="weekly">{t('reports:frequency.weekly')}</option>
                            <option value="monthly">{t('reports:frequency.monthly')}</option>
                        </select>
                    )}
                </Field>

                {data.schedule_frequency !== 'none' && (
                    <Field
                        label={t('reports:form.time.label')}
                        error={errors.schedule_time}
                        required
                        hint={t('reports:form.time.hint')}
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
                    <Field label={t('reports:form.dayOfWeek.label')} error={errors.schedule_day} required>
                        {(control) => (
                            <select
                                {...control}
                                className={controlClass}
                                value={data.schedule_day}
                                onChange={(event) => setData('schedule_day', event.target.value)}
                            >
                                {WEEKDAY_VALUES.map((day) => (
                                    <option key={day.value} value={day.value}>
                                        {t(`reports:weekday.${day.key}`)}
                                    </option>
                                ))}
                            </select>
                        )}
                    </Field>
                )}

                {data.schedule_frequency === 'monthly' && (
                    <Field
                        label={t('reports:form.dayOfMonth.label')}
                        error={errors.schedule_day}
                        required
                        hint={t('reports:form.dayOfMonth.hint')}
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
                label={t('reports:form.recipients.label')}
                error={[errors.recipients, ...recipientErrors].filter(Boolean).join(' ') || undefined}
                required
                hint={t('reports:form.recipients.hint')}
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
                {t('reports:form.active')}
            </label>

            <div className="flex justify-end gap-2">
                <Button
                    type="submit"
                    variant="primary"
                    aria-disabled={processing || undefined}
                    className={processing ? 'cursor-not-allowed opacity-60' : ''}
                >
                    {processing ? t('reports:form.saving') : mode === 'create' ? t('reports:form.createReport') : t('reports:form.saveChanges')}
                </Button>
            </div>
        </form>
    );
}
