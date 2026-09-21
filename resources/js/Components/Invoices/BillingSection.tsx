import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/Button';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { formatMoney } from '@/lib/money';
import type { InvoiceStatus, OrderInvoiceSummary, OrderStatus } from '@/types/generated';

const STATUS_TONE: Record<InvoiceStatus, BadgeTone> = {
    draft: 'neutral',
    sent: 'accent',
    paid: 'success',
    overdue: 'danger',
    void: 'neutral',
};

// §11.2 pas 7 / US-BILL-01 — la fel ca `SHIPPABLE_ORDER_STATUSES` din `ShipmentsSection`:
// litera Gherkin din specs.md §12.1 numește explicit doar „confirmed sau fulfilled"
// (vezi CONTRAZICERI din raportul livrat — `partially_fulfilled` rămâne exclus, deliberat,
// nu omis).
const INVOICEABLE_ORDER_STATUSES: OrderStatus[] = ['confirmed', 'fulfilled'];

/**
 * Secțiunea de facturare de pe `Orders/Show` (US-BILL-01) — „Create Invoice" sau, dacă
 * există deja una, un link către ea. Își cere SINGURĂ datele
 * (`GET .../orders/{order}/invoice-summary`, JSON simplu, ca `VariantCombobox` cu
 * `orders/variants/lookup`) în loc să le primească prin props de la
 * `OrderController::show()` — acel controller și `OrderResource` NU sunt fișiere ale
 * acestui lot (vezi docblock-ul `InvoiceController::forOrder()` pentru motiv complet).
 *
 * Regula din `.ai/rules/frontend.md` („niciun buton care duce la 403") e respectată prin
 * acest fetch: butonul „Create Invoice" apare doar după ce serverul a confirmat
 * `can.create`, nu doar pe baza statusului comenzii, vizibil oricui.
 */
export default function BillingSection({
    orderId,
    orderStatus,
    workspaceSlug,
}: {
    orderId: string;
    orderStatus: OrderStatus;
    workspaceSlug: string;
}) {
    const { t } = useTranslation('invoices');
    const locale = useLocale();
    const base = `/${workspaceSlug}`;
    const [summary, setSummary] = useState<OrderInvoiceSummary | null>(null);
    const [loading, setLoading] = useState(true);
    const [creating, setCreating] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const abortRef = useRef<AbortController | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        abortRef.current = controller;

        // `loading` pornește `true` (starea inițială) — un singur fetch la montare,
        // fără un `orderId` care se schimbă în viața paginii, deci nu are nevoie de un
        // `setLoading(true)` sincron aici (react-hooks/set-state-in-effect).
        fetch(`${base}/orders/${orderId}/invoice-summary`, {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Invoice summary lookup failed');
                }

                return response.json() as Promise<OrderInvoiceSummary>;
            })
            .then(setSummary)
            .catch((err: unknown) => {
                if (err instanceof DOMException && err.name === 'AbortError') {
                    return;
                }
                setSummary(null);
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [orderId, base]);

    const createInvoice = () => {
        if (creating) {
            return;
        }

        setCreating(true);
        setError(null);

        router.post(
            `${base}/orders/${orderId}/invoices`,
            {},
            {
                onError: (errors) => setError(Object.values(errors)[0] ?? t('invoices:billing.createError')),
                onFinish: () => setCreating(false),
            },
        );
    };

    const invoice = summary?.invoice ?? null;
    const canCreate = summary?.can.create ?? false;
    const canShowCreateButton = canCreate && invoice === null && INVOICEABLE_ORDER_STATUSES.includes(orderStatus);
    const hasVisibleContent = !loading && (invoice !== null || canShowCreateButton);

    // Review a11y (P2) — „loading → încărcat → absent" nu se anunța: un nod NOU se monta
    // per stare (`<section>…</section>` întors condiționat, sau `null`), niciodată ACELAȘI
    // nod cu textul schimbat, deci o regiune live n-avea ce mutație de DOM să observe.
    // Tiparul corect e cel din `ListUpdateAnnouncer.tsx`: un paragraf `sr-only` PERSISTENT,
    // randat necondiționat, al cărui text se schimbă.
    // `OrderInvoiceSummary.invoice` (JSON simplu, nu Resource Inertia) n-are `statusLabel`
    // — spre deosebire de `Invoice` din `types/generated.d.ts`, care îl are deja tradus
    // din backend. Aici traducem noi statusul, dintr-un catalog local.
    //
    // Set de chei PROPRIU (`billing.statusBadge.*`), nu `invoices:status.*` pe care le
    // folosește filtrul din `Invoices/Index`, fiindcă cele două au registre diferite — și
    // le aveau și înaintea acestui val: filtrul era un array cu `label: 'Paid'`, pe când
    // chip-ul de aici randa `{invoice.status}` BRUT, adică `paid`, minuscul. Inconsecvența
    // e preexistentă (lista de facturi arată „Paid" prin `invoice.statusLabel` de la
    // server, ecranul de comandă arăta „paid"), iar valul de i18n o păstrează exact, nu o
    // repară pe furiș: `e2e/specs/order-fulfilment.spec.ts:220` caută `'paid'` cu
    // `{ exact: true }`. Unificarea celor două registre e o decizie de QA vizual bilingv
    // (Val 5), nu o schimbare de strecurat într-o extragere de string-uri.
    const invoiceStatusLabel = invoice ? t(`invoices:billing.statusBadge.${invoice.status}`) : '';

    const statusMessage = loading
        ? t('invoices:billing.statusLoading')
        : invoice
          ? t('invoices:billing.statusFound', { number: invoice.invoiceNumber ?? '', status: invoiceStatusLabel })
          : canShowCreateButton
            ? t('invoices:billing.statusCreatable')
            : t('invoices:billing.statusUnavailable');

    return (
        <>
            <p role="status" aria-live="polite" aria-atomic="true" className="sr-only">
                {statusMessage}
            </p>

            {loading && (
                <section aria-label={t('invoices:billing.sectionAria')} className="rounded-lg border border-border bg-surface p-4">
                    <p aria-hidden="true" className="text-sm text-text-3">
                        {t('invoices:billing.loading')}
                    </p>
                </section>
            )}

            {hasVisibleContent && (
                <section aria-label={t('invoices:billing.sectionAria')} className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                    <h2 className="text-sm font-semibold text-text">{t('invoices:billing.heading')}</h2>

                    {error && (
                        <p role="alert" className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                            {error}
                        </p>
                    )}

                    {invoice ? (
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <Link href={`${base}/invoices/${invoice.id}`} className="font-medium text-accent-text hover:underline">
                                {invoice.invoiceNumber ?? t('invoices:billing.viewInvoice')}
                            </Link>
                            <StatusBadge tone={STATUS_TONE[invoice.status]}>{invoiceStatusLabel}</StatusBadge>
                            {invoice.balanceDue > 0 && invoice.status !== 'void' && (
                                <span className="numeric text-text-2">
                                    {t('invoices:billing.balanceDue', { amount: formatMoney(invoice.balanceDue, invoice.currency, locale) })}
                                </span>
                            )}
                        </div>
                    ) : (
                        <Button
                            variant="primary"
                            onClick={createInvoice}
                            aria-disabled={creating || undefined}
                            className={creating ? 'cursor-not-allowed opacity-60' : ''}
                        >
                            {creating ? t('invoices:billing.creating') : t('invoices:billing.create')}
                        </Button>
                    )}
                </section>
            )}
        </>
    );
}
