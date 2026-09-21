import { Head, usePage } from '@inertiajs/react';
import { useMemo, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ButtonLink } from '@/Components/Button';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber, getDateTimeFormat } from '@/lib/format';
import { type AppLocale } from '@/lib/i18n';
import { buildImportStatusLabels, IMPORT_STATUS_TONES } from '@/lib/importStatus';
import AppLayout from '@/Layouts/AppLayout';
import type { ImportsIndexPageProps, ImportSummary } from '@/types/generated';
import type { TFunction } from 'i18next';

/**
 * `row.createdAt` folosea `new Date(...).toLocaleString('en-US')` FĂRĂ opțiuni — pe un
 * `Date`, asta produce dată ȘI oră cu secunde („9/21/2026, 5:30:00 PM"), distinct de
 * `Intl.DateTimeFormat` fără opțiuni (doar dată). Verificat direct (Node), nu presupus:
 * `Date.prototype.toLocaleString()` completează ambele componente când niciuna nu e
 * specificată, spre deosebire de constructorul `Intl.DateTimeFormat` gol. Formă proprie,
 * păstrată ca `const` de modul (Val 3, „Lot I18N", `.ai/rules/frontend.md` §6).
 */
const UPLOADED_AT_OPTIONS: Intl.DateTimeFormatOptions = {
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
    hour: 'numeric',
    minute: 'numeric',
    second: 'numeric',
};

/**
 * Imports/Index — §14, US-IMP-01/02. Owner/Manager (`imports.view`) — Agent și Viewer nu
 * ajung aici (nici în navigație, `AppLayout.tsx`, nici la nivel de rută, `ImportPolicy`).
 *
 * `IMPORT_STATUS_TONES`/`buildImportStatusLabels` (`lib/importStatus.ts`) — partajate cu
 * `Imports/Show.tsx`. Fișierul a fost extins ulterior la lotul A3 (era `.ts`, în afara
 * partiționării inițiale pe `.tsx`); etichetele trec acum prin fabrica `buildImportStatusLabels(t)`,
 * memoizată aici cu `useMemo`, exact tiparul `buildStepTitles(t)` din `Imports/Show.tsx`.
 */
export default function Index() {
    const { imports, can, workspace } = usePage<ImportsIndexPageProps>().props;
    const { t } = useTranslation('imports');
    const locale = useLocale();
    const statusLabels = useMemo(() => buildImportStatusLabels(t), [t]);
    const base = workspace ? `/${workspace.slug}` : '';

    return (
        <>
            <Head title={t('imports:index.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('imports:index.title')}
                    description={t('imports:index.description')}
                    actions={
                        can.create && (
                            <ButtonLink variant="primary" href={`${base}/imports/create`}>
                                {t('imports:index.newImport')}
                            </ButtonLink>
                        )
                    }
                />

                {imports.length === 0 ? (
                    <EmptyState
                        message={t('imports:index.empty')}
                        action={
                            can.create && (
                                <ButtonLink variant="primary" href={`${base}/imports/create`}>
                                    {t('imports:index.startFirstImport')}
                                </ButtonLink>
                            )
                        }
                    />
                ) : (
                    <div className="overflow-hidden rounded-lg border border-border">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">{t('imports:index.title')}</caption>
                            <thead className="bg-raised text-text-2">
                                <tr>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('imports:index.columns.file')}</th>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('imports:index.columns.resource')}</th>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('imports:index.columns.status')}</th>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('imports:index.columns.rows')}</th>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('imports:index.columns.uploadedBy')}</th>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('imports:index.columns.uploadedAt')}</th>
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
                                        {/* `row.resourceLabel` vine din backend (`ImportableResources::resolve()->label()`),
                                            NETRADUS server-side (verificat: `AccountImportResource::label()` întoarce
                                            literalul englez „Accounts", fără `__()`) — FR-I18N-06 tratează totuși orice
                                            prop Inertia ca sursă unică, nu re-tradusă în frontend. Vezi raportul lotului. */}
                                        <td className="px-4 py-2.5 text-text-2">{row.resourceLabel}</td>
                                        <td className="px-4 py-2.5">
                                            <StatusBadge tone={IMPORT_STATUS_TONES[row.status]}>{statusLabels[row.status]}</StatusBadge>
                                        </td>
                                        <td className="px-4 py-2.5 numeric text-text-2">{formatRowCounts(row, t, locale)}</td>
                                        <td className="px-4 py-2.5 text-text-2">{row.createdBy?.name ?? '—'}</td>
                                        <td className="px-4 py-2.5 numeric text-text-2">
                                            {row.createdAt ? getDateTimeFormat(locale, UPLOADED_AT_OPTIONS).format(new Date(row.createdAt)) : '—'}
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

function formatRowCounts(row: ImportSummary, t: TFunction, locale: AppLocale): string {
    if (row.totalRows === null) {
        return '—';
    }

    if (row.validRows === null || row.errorRows === null) {
        return t('imports:index.rowCounts.totalOnly', { count: row.totalRows, formattedCount: formatNumber(row.totalRows, locale) });
    }

    return t('imports:index.rowCounts.validInvalid', {
        valid: formatNumber(row.validRows, locale),
        invalid: formatNumber(row.errorRows, locale),
    });
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
