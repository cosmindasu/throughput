import { Deferred, Head, Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { buttonClass } from '@/Components/Button';
import CursorPagination from '@/Components/CursorPagination';
import EmptyState from '@/Components/EmptyState';
import { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useListFilters } from '@/hooks/useListFilters';
import AppLayout from '@/Layouts/AppLayout';
import { formatMoney } from '@/lib/money';
import type { Invoice, InvoiceStatus, InvoicesIndexPageProps } from '@/types/generated';

/**
 * `can` e propul FIECĂREI pagini (§1.2 regula 2 din plan), declarat în `*PageProps` din
 * `resources/js/types/generated.d.ts` — fișier de INTEGRARE, neatins de acest lot. Până
 * când propul ajunge acolo (blocul exact e în raport), forma închisă stă aici, ca `tsc` să
 * nu vadă `unknown`.
 */
interface InvoicesIndexProps extends InvoicesIndexPageProps {
    can: { export: boolean };
}

const dateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' });

const STATUSES: Array<{ value: InvoiceStatus | ''; label: string }> = [
    { value: '', label: 'All statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'sent', label: 'Sent' },
    { value: 'paid', label: 'Paid' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'void', label: 'Void' },
];

const STATUS_TONE: Record<InvoiceStatus, BadgeTone> = {
    draft: 'neutral',
    sent: 'accent',
    paid: 'success',
    overdue: 'danger',
    void: 'neutral',
};

/**
 * `Invoices/Index` — specs.md §12.1, „listă Invoices (paginare pe cursor, filtre ca la
 * Orders)". Deliberat mai simplă decât `Orders/Index`: fără selector de coloane, fără
 * vizualizări salvate și fără operații în masă — niciuna nu e cerută de brief-ul acestui
 * lot pentru Facturi, iar `App\Support\SavedViews\SavedViewResourceType` (catalogul de
 * resurse cu vizualizări salvate) e un fișier comun, neatins de acest lot (vezi raportul
 * livrat).
 */
export default function Index() {
    const page = usePage<InvoicesIndexProps>();
    const { filters, workspace, can } = page.props;
    const base = workspace ? `/${workspace.slug}` : '';
    const { setFilter } = useListFilters(filters);
    const [search, setSearch] = useState(filters.filter.q ?? '');

    return (
        <>
            <Head title="Invoices" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Invoices"
                    actions={
                        can.export && (
                            <>
                                <a href={buildExportHref(page.url, base, 'csv')} className={buttonClass('secondary')}>
                                    Export CSV
                                </a>
                                <a href={buildExportHref(page.url, base, 'zip')} className={buttonClass('secondary')}>
                                    Export PDFs (zip)
                                </a>
                            </>
                        )
                    }
                />

                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-text">Search</span>
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
                            placeholder="Search by invoice number…"
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-text">Status</span>
                        <select
                            className={controlClass}
                            value={filters.filter.status ?? ''}
                            onChange={(event) => setFilter('status', event.target.value || null)}
                        >
                            {STATUSES.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-text">From</span>
                        <input
                            type="date"
                            className={controlClass}
                            value={filters.filter.from ?? ''}
                            onChange={(event) => setFilter('from', event.target.value || null)}
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-text">To</span>
                        <input
                            type="date"
                            className={controlClass}
                            value={filters.filter.to ?? ''}
                            onChange={(event) => setFilter('to', event.target.value || null)}
                        />
                    </label>
                </div>

                <Deferred data="invoices" fallback={<TableSkeleton columns={6} />}>
                    <InvoicesTable base={base} />
                </Deferred>
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

function InvoicesTable({ base }: { base: string }) {
    const { invoices } = usePage<InvoicesIndexPageProps>().props;

    if (invoices.data.length === 0) {
        return <EmptyState message="No invoices match this filter." />;
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="overflow-x-auto rounded-lg border border-border bg-surface">
                <table className="w-full text-left text-sm">
                    <caption className="sr-only">Invoices</caption>
                    <thead>
                        <tr className="border-b border-border-soft text-xs text-text-3">
                            <th scope="col" className="px-4 py-2 font-medium">
                                Invoice number
                            </th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                Status
                            </th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                Account
                            </th>
                            <th scope="col" className="px-4 py-2 text-right font-medium">
                                Total
                            </th>
                            <th scope="col" className="px-4 py-2 text-right font-medium">
                                Balance due
                            </th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                Due date
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
    return (
        <tr className="border-b border-border-soft last:border-b-0 hover:bg-row-hover">
            <td className="numeric px-4 py-2">
                <Link href={`${base}/invoices/${invoice.id}`} className="font-medium text-text hover:underline">
                    {invoice.invoiceNumber ?? `#${invoice.id.slice(-8)}`}
                </Link>
            </td>
            <td className="px-4 py-2">
                <StatusBadge tone={STATUS_TONE[invoice.status]}>{invoice.statusLabel}</StatusBadge>
            </td>
            <td className="px-4 py-2">{invoice.order?.account?.name ?? '—'}</td>
            <td className="numeric whitespace-nowrap px-4 py-2 text-right">{formatMoney(invoice.total, invoice.currency)}</td>
            <td className="numeric whitespace-nowrap px-4 py-2 text-right">{formatMoney(invoice.balanceDue, invoice.currency)}</td>
            <td className="whitespace-nowrap px-4 py-2">{invoice.dueDate ? dateFormatter.format(new Date(invoice.dueDate)) : '—'}</td>
        </tr>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
