import { Head, Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { buttonClass } from '@/Components/Button';
import CursorPagination from '@/Components/CursorPagination';
import DeferredData from '@/Components/DeferredData';
import EmptyState from '@/Components/EmptyState';
import { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useListFilters } from '@/hooks/useListFilters';
import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';
import { formatDate } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import type { Invoice, InvoiceStatus, InvoicesIndexPageProps } from '@/types/generated';

const STATUS_VALUES: Array<InvoiceStatus | ''> = ['', 'draft', 'sent', 'paid', 'overdue', 'void'];

const STATUS_TONE: Record<InvoiceStatus, BadgeTone> = {
    draft: 'neutral',
    sent: 'accent',
    paid: 'success',
    overdue: 'danger',
    void: 'neutral',
};

/** Eticheta filtrului de status (array hardcodat în frontend, distinct de `invoice.statusLabel` care vine deja tradus din backend). */
function statusFilterLabel(t: (key: string) => string, value: InvoiceStatus | ''): string {
    return value === '' ? t('invoices:status.all') : t(`invoices:status.${value}`);
}

/**
 * `Invoices/Index` — specs.md §12.1, „listă Invoices (paginare pe cursor, filtre ca la
 * Orders)". Deliberat mai simplă decât `Orders/Index`: fără selector de coloane, fără
 * vizualizări salvate și fără operații în masă — niciuna nu e cerută de brief-ul acestui
 * lot pentru Facturi, iar `App\Support\SavedViews\SavedViewResourceType` (catalogul de
 * resurse cu vizualizări salvate) e un fișier comun, neatins de acest lot (vezi raportul
 * livrat).
 */
export default function Index() {
    const { t } = useTranslation('invoices');
    const page = usePage<InvoicesIndexPageProps>();
    const { filters, workspace, can } = page.props;
    const base = workspace ? `/${workspace.slug}` : '';
    const { setFilter } = useListFilters(filters);
    const [search, setSearch] = useState(filters.filter.q ?? '');

    return (
        <>
            <Head title={t('invoices:index.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('invoices:index.title')}
                    actions={
                        can.export && (
                            <>
                                <a href={buildExportHref(page.url, base, 'csv')} className={buttonClass('secondary')}>
                                    {t('invoices:index.exportCsv')}
                                </a>
                                <a href={buildExportHref(page.url, base, 'zip')} className={buttonClass('secondary')}>
                                    {t('invoices:index.exportPdfZip')}
                                </a>
                            </>
                        )
                    }
                />

                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-text">{t('invoices:index.filters.search.label')}</span>
                        <input
                            type="search"
                            className={controlClass}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    setFilter('q', search || null);
                                }
                            }}
                            onBlur={() => setFilter('q', search || null)}
                            placeholder={t('invoices:index.filters.search.placeholder')}
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-text">{t('invoices:index.filters.status.label')}</span>
                        <select
                            className={controlClass}
                            value={filters.filter.status ?? ''}
                            onChange={(event) => setFilter('status', event.target.value || null)}
                        >
                            {STATUS_VALUES.map((value) => (
                                <option key={value} value={value}>
                                    {statusFilterLabel(t, value)}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-text">{t('invoices:index.filters.from')}</span>
                        <input
                            type="date"
                            className={controlClass}
                            value={filters.filter.from ?? ''}
                            onChange={(event) => setFilter('from', event.target.value || null)}
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-text">{t('invoices:index.filters.to')}</span>
                        <input
                            type="date"
                            className={controlClass}
                            value={filters.filter.to ?? ''}
                            onChange={(event) => setFilter('to', event.target.value || null)}
                        />
                    </label>
                </div>

                <DeferredData<InvoicesIndexPageProps, 'invoices'>
                    keys={['invoices']}
                    fallback={<TableSkeleton columns={6} />}
                >
                    {({ invoices }) => <InvoicesTable base={base} invoices={invoices} />}
                </DeferredData>
            </div>
        </>
    );
}

/**
 * §13.2 — filtrul/sortul curent, propagat la export, la fel ca `Orders/Index`: fișierul
 * conține EXACT rândurile de pe ecran, nu toată lista.
 */
function buildExportHref(currentUrl: string, base: string, format: 'csv' | 'zip'): string {
    const query = currentUrl.split('?')[1];
    const exportPath = `${base}/invoices/export`;

    return query ? `${exportPath}?${query}&format=${format}` : `${exportPath}?format=${format}`;
}

function InvoicesTable({ base, invoices }: { base: string; invoices: NonNullable<InvoicesIndexPageProps['invoices']> }) {
    const { t } = useTranslation('invoices');

    if (invoices.data.length === 0) {
        return <EmptyState message={t('invoices:index.empty')} />;
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="overflow-x-auto rounded-lg border border-border bg-surface">
                <table className="w-full text-left text-sm">
                    <caption className="sr-only">{t('invoices:index.title')}</caption>
                    <thead>
                        <tr className="border-b border-border-soft text-xs text-text-3">
                            <th scope="col" className="px-4 py-2 font-medium">
                                {t('invoices:index.columns.invoiceNumber')}
                            </th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                {t('invoices:index.columns.status')}
                            </th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                {t('invoices:index.columns.account')}
                            </th>
                            <th scope="col" className="px-4 py-2 text-right font-medium">
                                {t('invoices:index.columns.total')}
                            </th>
                            <th scope="col" className="px-4 py-2 text-right font-medium">
                                {t('invoices:index.columns.balanceDue')}
                            </th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                {t('invoices:index.columns.dueDate')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoices.data.map((invoice) => (
                            <InvoiceRow key={invoice.id} invoice={invoice} base={base} />
                        ))}
                    </tbody>
                </table>
            </div>

            <CursorPagination nextCursor={invoices.nextCursor} prevCursor={invoices.prevCursor} />
        </div>
    );
}

function InvoiceRow({ invoice, base }: { invoice: Invoice; base: string }) {
    const locale = useLocale();

    return (
        <tr className="border-b border-border-soft last:border-b-0 hover:bg-row-hover">
            <td className="numeric px-4 py-2">
                <Link href={`${base}/invoices/${invoice.id}`} className="font-medium text-text hover:underline">
                    {invoice.invoiceNumber ?? `#${invoice.id.slice(-8)}`}
                </Link>
            </td>
            <td className="px-4 py-2">
                {/* `invoice.statusLabel` vine deja tradus din backend (App\Http\Resources\
                    InvoiceResource) — nu se retraduce în frontend. */}
                <StatusBadge tone={STATUS_TONE[invoice.status]}>{invoice.statusLabel}</StatusBadge>
            </td>
            <td className="px-4 py-2">{invoice.order?.account?.name ?? '—'}</td>
            <td className="numeric whitespace-nowrap px-4 py-2 text-right">{formatMoney(invoice.total, invoice.currency, locale)}</td>
            <td className="numeric whitespace-nowrap px-4 py-2 text-right">{formatMoney(invoice.balanceDue, invoice.currency, locale)}</td>
            <td className="whitespace-nowrap px-4 py-2">{invoice.dueDate ? formatDate(invoice.dueDate, locale) : '—'}</td>
        </tr>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
