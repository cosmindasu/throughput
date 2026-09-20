import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Button from '@/Components/Button';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
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
                onError: (errors) => setError(Object.values(errors)[0] ?? 'This invoice could not be created.'),
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
    const statusMessage = loading
        ? 'Loading billing status.'
        : invoice
          ? `Invoice ${invoice.invoiceNumber ?? ''} found, status ${invoice.status}.`
          : canShowCreateButton
            ? 'No invoice yet for this order — you can create one.'
            : 'No billing information available for this order.';

    return (
        <>
            <p role="status" aria-live="polite" aria-atomic="true" className="sr-only">
                {statusMessage}
            </p>

            {loading && (
                <section aria-label="Billing" className="rounded-lg border border-border bg-surface p-4">
                    <p aria-hidden="true" className="text-sm text-text-3">
                        Loading billing status…
                    </p>
                </section>
            )}

            {hasVisibleContent && (
                <section aria-label="Billing" className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                    <h2 className="text-sm font-semibold text-text">Billing</h2>

                    {error && (
                        <p role="alert" className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                            {error}
                        </p>
                    )}

                    {invoice ? (
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <Link href={`${base}/invoices/${invoice.id}`} className="font-medium text-accent-text hover:underline">
                                {invoice.invoiceNumber ?? 'View invoice'}
                            </Link>
                            <StatusBadge tone={STATUS_TONE[invoice.status]}>{invoice.status}</StatusBadge>
                            {invoice.balanceDue > 0 && invoice.status !== 'void' && (
                                <span className="numeric text-text-2">Balance due: {formatMoney(invoice.balanceDue, invoice.currency)}</span>
                            )}
                        </div>
                    ) : (
                        <Button
                            variant="primary"
                            onClick={createInvoice}
                            aria-disabled={creating || undefined}
                            className={creating ? 'cursor-not-allowed opacity-60' : ''}
                        >
                            {creating ? 'Creating…' : 'Create Invoice'}
                        </Button>
                    )}
                </section>
            )}
        </>
    );
}
