import { Head, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/lib/format';
import AppLayout from '@/Layouts/AppLayout';
import type { ImportsCreatePageProps } from '@/types/generated';

/**
 * Imports/Create — Pasul 1 (Upload, §14.1). Limitele afișate (`limits`) sunt cele
 * SERVER-SIDE (`config('throughput.limits.import_*')`) — nu o constantă front-end care ar
 * putea diverge.
 *
 * `Field`/`controlClass` (`Components/Form/Field.tsx`), nu stil manual — găsit la audit:
 * varianta manuală anterioară pierdea `aria-[invalid=true]:border-danger` (indiciul vizual
 * de eroare pe bordură) ȘI concatenarea `aria-describedby` (indiciul de dimensiune/rânduri
 * dispărea exact când eroarea de fișier prea mare ar fi avut nevoie de context). `Field`
 * rezolvă ambele o singură dată.
 *
 * `resources[].label` vine din backend (`ImportableResources::resolve()->label()`), deja
 * TRADUS server-side (`__('imports.resources.<tip>')`, `lang/{en,fr}/imports.php`), în
 * locale-ul cererii curente (FR-I18N-04, ADR-022). Nu se re-traduce aici — vezi nota
 * identică din `Imports/Index.tsx`.
 */
export default function Create() {
    const { resources, limits, workspace } = usePage<ImportsCreatePageProps>().props;
    const { t } = useTranslation('imports');
    const locale = useLocale();
    const base = workspace ? `/${workspace.slug}` : '';

    const { data, setData, post, processing, errors } = useForm<{
        resource_type: string;
        file: File | null;
    }>({
        resource_type: resources[0]?.value ?? '',
        file: null,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (processing) {
            return;
        }

        post(`${base}/imports`, { forceFormData: true });
    };

    const templateHref = data.resource_type ? `${base}/imports/template/${data.resource_type}` : undefined;

    return (
        <>
            <Head title={t('imports:create.title')} />

            <div className="flex max-w-xl flex-col gap-6">
                <PageHeader title={t('imports:create.title')} description={t('imports:create.description')} />

                <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
                    <Field label={t('imports:create.resourceLabel')} error={errors.resource_type}>
                        {(control) => (
                            <>
                                <select
                                    {...control}
                                    value={data.resource_type}
                                    onChange={(event) => setData('resource_type', event.target.value)}
                                    className={controlClass}
                                >
                                    {resources.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                {templateHref && (
                                    <a href={templateHref} className="mt-1 self-start text-sm text-accent-text hover:underline">
                                        {t('imports:create.downloadTemplate')}
                                    </a>
                                )}
                            </>
                        )}
                    </Field>

                    <Field
                        label={t('imports:create.fileLabel')}
                        required
                        hint={t('imports:create.fileHint', {
                            count: limits.maxRows,
                            maxFileMb: limits.maxFileMb,
                            maxRows: formatNumber(limits.maxRows, locale),
                        })}
                        error={errors.file}
                    >
                        {(control) => (
                            <input
                                {...control}
                                type="file"
                                required
                                aria-required="true"
                                accept=".csv,.txt,.xlsx"
                                onChange={(event) => setData('file', event.target.files?.[0] ?? null)}
                                className={`${controlClass} file:mr-3 file:rounded file:border-0 file:bg-raised file:px-3 file:py-1 file:text-text-2`}
                            />
                        )}
                    </Field>

                    <div>
                        <Button
                            type="submit"
                            variant="primary"
                            aria-disabled={processing || !data.file || undefined}
                            className={processing || !data.file ? 'cursor-not-allowed opacity-60' : ''}
                            onClick={(event) => {
                                if (processing || !data.file) {
                                    event.preventDefault();
                                }
                            }}
                        >
                            {processing ? t('imports:create.uploading') : t('imports:create.upload')}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

Create.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
