import { Deferred, Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import CursorPagination from '@/Components/CursorPagination';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useListFilters } from '@/hooks/useListFilters';
import { useLocale } from '@/hooks/useLocale';
import AppLayout from '@/Layouts/AppLayout';
import { formatNumber, getDateTimeFormat } from '@/lib/format';
import type { StockHistoryPageProps, StockMovementReason, StockMovementRow } from '@/types/generated';

// Forma NUMERICĂ completă a lui `toLocaleString('en-US')` fără opțiuni — „3/14/2026,
// 5:09:26 PM" — distinctă de `formatDateTime` („Mar 14, 2026, 5:09 PM"), care ar rupe
// selectorii E2E fixați pe această formă (`.ai/rules/frontend.md` / brief-ul Val 3).
const DATE_TIME_NUMERIC: Intl.DateTimeFormatOptions = {};

/**
 * Stock/History — FR-STOCK-03. Fiecare mișcare e o linie a registrului append-only
 * (ADR-004): nimic pe acest ecran se editează sau se șterge, indiferent de rol.
 */
export default function History() {
    const { t } = useTranslation('products');
    const locale = useLocale();
    const { variant, movements, list, reasons } = usePage<StockHistoryPageProps>().props;
    const { setFilter, setSort } = useListFilters(list);
    const hasFilters = Object.keys(list.filter).length > 0;
    // `variant.sku` e conținut scris de utilizator (FR-I18N-06) — interpolat, nu tradus.
    const title = t('products:stock.history.title', { sku: variant.sku });

    return (
        <>
            <Head title={title} />

            <div className="flex flex-col gap-6">
                <PageHeader title={title} description={variant.productName} />

                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        {t('products:stock.history.filters.reason.label')}
                        <select
                            value={list.filter.reason ?? ''}
                            onChange={(event) => setFilter('reason', event.target.value || null)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="">{t('products:stock.history.filters.reason.any')}</option>
                            {/* Etichete cu MINUSCULĂ în catalog: ecranul randa înainte
                                `{movement.reason}` brut, adică enum-ul („receipt", „sale").
                                Păstrat identic — vezi nota din `Settings/Billing/Index.tsx`. */}
                            {reasons.map((reason) => (
                                <option key={reason} value={reason}>
                                    {t(`products:stock.history.reasons.${reason}`)}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        {t('products:stock.history.filters.from')}
                        <input
                            type="date"
                            value={list.filter.from ?? ''}
                            onChange={(event) => setFilter('from', event.target.value || null)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        {t('products:stock.history.filters.to')}
                        <input
                            type="date"
                            value={list.filter.to ?? ''}
                            onChange={(event) => setFilter('to', event.target.value || null)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        {t('products:stock.history.filters.sort.label')}
                        <select
                            value={list.sort}
                            onChange={(event) => setSort(event.target.value)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="-created_at">{t('products:stock.history.filters.sort.newest')}</option>
                            <option value="created_at">{t('products:stock.history.filters.sort.oldest')}</option>
                        </select>
                    </label>
                </div>

                <Deferred data="movements" fallback={<TableSkeleton columns={6} />}>
                    {movements && movements.data.length > 0 ? (
                        <div className="data-table-scroll rounded-lg border border-border" tabIndex={0}>
                            <table className="data-table w-full text-left text-sm">
                                <caption className="sr-only">{t('products:stock.history.tableCaption', { sku: variant.sku })}</caption>
                                <thead className="bg-raised text-text-2">
                                    <tr>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('products:stock.history.columns.date')}
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('products:stock.history.columns.reason')}
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('products:stock.history.columns.location')}
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('products:stock.history.columns.change')}
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('products:stock.history.columns.note')}
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('products:stock.history.columns.by')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border-soft bg-surface">
                                    {movements.data.map((movement: StockMovementRow) => (
                                        <tr key={movement.id}>
                                            <td className="px-4 py-2.5 text-text-2">
                                                {movement.createdAt
                                                    ? getDateTimeFormat(locale, DATE_TIME_NUMERIC).format(new Date(movement.createdAt))
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <StatusBadge tone={reasonTone(movement.reason)}>
                                                    {t(`products:stock.history.reasons.${movement.reason}`)}
                                                </StatusBadge>
                                            </td>
                                            <td className="px-4 py-2.5 text-text-2">{movement.location.name}</td>
                                            <td className={`px-4 py-2.5 tabular-nums font-medium ${movement.delta >= 0 ? 'text-success' : 'text-danger'}`}>
                                                {movement.delta >= 0 ? `+${formatNumber(movement.delta, locale)}` : formatNumber(movement.delta, locale)}
                                            </td>
                                            <td className="px-4 py-2.5 text-text-2">{movement.note ?? '—'}</td>
                                            <td className="px-4 py-2.5 text-text-2">{movement.createdBy?.name ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        movements && (
                            <EmptyState
                                message={
                                    hasFilters
                                        ? t('products:stock.history.empty.filtered')
                                        : t('products:stock.history.empty.none')
                                }
                            />
                        )
                    )}
                </Deferred>

                {movements && <CursorPagination nextCursor={movements.nextCursor} prevCursor={movements.prevCursor} />}
            </div>
        </>
    );
}

function reasonTone(reason: StockMovementReason): BadgeTone {
    return {
        receipt: 'success' as const,
        sale: 'info' as const,
        adjustment: 'warning' as const,
        return: 'accent' as const,
        transfer: 'neutral' as const,
    }[reason];
}

History.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
