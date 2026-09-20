import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import HistoryTab from '@/Components/History/HistoryTab';
import BillingSection from '@/Components/Invoices/BillingSection';
import OrderTimeline from '@/Components/Orders/OrderTimeline';
import ShipmentsSection from '@/Components/Orders/ShipmentsSection';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import { formatMoney } from '@/lib/money';
import type { OrderStatus, OrdersShowPageProps } from '@/types/generated';

const dateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' });
const dateTimeFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short' });

const STATUS_TONE: Record<OrderStatus, 'neutral' | 'accent' | 'success' | 'danger'> = {
    draft: 'neutral',
    confirmed: 'accent',
    partially_fulfilled: 'accent',
    fulfilled: 'success',
    cancelled: 'danger',
};

/**
 * §11.2/§11.4 — detaliul comenzii, cu tranzițiile de stare din pagina asta:
 * „Confirm order" (`ConfirmOrderController`, cu prompt de backorder cerut de SERVER,
 * BR-STOCK-04 — nu doar decis în UI) și „Cancel" (`CancelOrderController`, BR-ORD-01).
 */
export default function Show() {
    const { order, can, workspace } = usePage<OrdersShowPageProps>().props;
    const workspaceSlug = workspace?.slug ?? '';

    const [confirming, setConfirming] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [backorderMessage, setBackorderMessage] = useState<string | null>(null);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const confirmUrl = `/${workspaceSlug}/orders/${order.id}/confirm`;
    const cancelUrl = `/${workspaceSlug}/orders/${order.id}/cancel`;

    const submitConfirm = (acknowledgeBackorder: boolean) => {
        setProcessing(true);
        setErrorMessage(null);

        router.patch(
            confirmUrl,
            { acknowledge_backorder: acknowledgeBackorder },
            {
                onSuccess: () => {
                    setConfirming(false);
                    setBackorderMessage(null);
                },
                onError: (errors) => {
                    if (errors.acknowledge_backorder) {
                        // BR-STOCK-04 — server-ul, nu clientul, decide dacă era nevoie de
                        // acest prompt: un client care nu-l anticipează corect tot ajunge
                        // aici la a doua încercare, cu mesajul exact al regulii.
                        setBackorderMessage(errors.acknowledge_backorder);
                    } else {
                        setErrorMessage(Object.values(errors)[0] ?? 'This order could not be confirmed.');
                        setConfirming(false);
                    }
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const submitCancel = () => {
        setProcessing(true);
        router.patch(cancelUrl, {}, {
            onSuccess: () => setCancelling(false),
            onError: (errors) => setErrorMessage(Object.values(errors)[0] ?? 'This order could not be cancelled.'),
            onFinish: () => setProcessing(false),
        });
    };

    const destroy = () => {
        setProcessing(true);
        router.delete(`/${workspaceSlug}/orders/${order.id}`, {
            onFinish: () => {
                setProcessing(false);
                setConfirmingDelete(false);
            },
        });
    };

    return (
        <>
            <Head title={order.orderNumber ?? 'Order'} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={order.orderNumber ?? `Draft — ${order.account.name}`}
                    description={
                        <span className="flex flex-wrap items-center gap-2">
                            <Link href={`/${workspaceSlug}/accounts/${order.account.id}`} className="hover:underline">
                                {order.account.name}
                            </Link>
                            <StatusBadge tone={STATUS_TONE[order.status]}>{order.statusLabel}</StatusBadge>
                        </span>
                    }
                    actions={
                        <>
                            {can.confirm && (
                                <button
                                    type="button"
                                    onClick={() => setConfirming(true)}
                                    className="rounded-md bg-accent-fill px-3 py-1.5 text-sm font-medium text-accent-on transition-colors hover:bg-accent-fill-hover"
                                >
                                    Confirm order
                                </button>
                            )}
                            {can.edit && <ButtonLink href={`/${workspaceSlug}/orders/${order.id}/edit`}>Edit</ButtonLink>}
                            {can.cancel && (
                                <button
                                    type="button"
                                    onClick={() => setCancelling(true)}
                                    className="rounded-md border border-danger px-3 py-1.5 text-sm text-danger transition-colors hover:bg-danger-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >
                                    Cancel order
                                </button>
                            )}
                            {can.delete && (
                                <button
                                    type="button"
                                    onClick={() => setConfirmingDelete(true)}
                                    className="rounded-md border border-danger px-3 py-1.5 text-sm text-danger transition-colors hover:bg-danger-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >
                                    Delete
                                </button>
                            )}
                        </>
                    }
                />

                {errorMessage && (
                    <p role="alert" className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                        {errorMessage}
                    </p>
                )}

                <dl className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label="Grand total" value={<span className="numeric">{formatMoney(order.grandTotal, order.currency)}</span>} />
                    <Field label="Owner" value={order.owner.name} />
                    <Field label="Contact" value={order.contact ? (order.contact.isAnonymized ? 'Anonymized contact' : order.contact.name) : '—'} />
                    <Field label="Placed at" value={order.placedAt ? dateTimeFormatter.format(new Date(order.placedAt)) : '—'} />
                    <Field label="Deal" value={order.deal?.title ?? '—'} />
                    <Field label="Created" value={order.createdAt ? dateFormatter.format(new Date(order.createdAt)) : '—'} />
                </dl>

                {order.notes && (
                    <section aria-label="Notes" className="rounded-lg border border-border bg-surface p-4 text-sm text-text-2">
                        {order.notes}
                    </section>
                )}

                {/* FR-ORD-03 — creat → confirmat → shipment(uri). */}
                <OrderTimeline order={order} />

                <section aria-label="Lines" className="overflow-x-auto rounded-lg border border-border bg-surface">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="border-b border-border-soft text-xs text-text-3">
                                <th scope="col" className="px-4 py-2 font-medium">
                                    Line
                                </th>
                                <th scope="col" className="px-4 py-2 text-right font-medium">
                                    Quantity
                                </th>
                                <th scope="col" className="px-4 py-2 text-right font-medium">
                                    Fulfilled
                                </th>
                                <th scope="col" className="px-4 py-2 text-right font-medium">
                                    Unit price
                                </th>
                                <th scope="col" className="px-4 py-2 text-right font-medium">
                                    Line total
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {order.lines.map((line) => (
                                <tr key={line.id} className="border-b border-border-soft last:border-b-0">
                                    <td className="px-4 py-2">{line.description}</td>
                                    <td className="numeric px-4 py-2 text-right">{line.quantity}</td>
                                    <td className="px-4 py-2 text-right">
                                        <span className="numeric block">
                                            {line.quantityFulfilled > 0
                                                ? `Shipped ${line.quantityFulfilled} of ${line.quantity}`
                                                : 'Not yet shipped'}
                                        </span>
                                    </td>
                                    <td className="numeric whitespace-nowrap px-4 py-2 text-right">{formatMoney(line.unitPrice, order.currency)}</td>
                                    <td className="numeric whitespace-nowrap px-4 py-2 text-right">{formatMoney(line.lineTotal, order.currency)}</td>
                                </tr>
                            ))}
                            {order.lines.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-4 py-3 text-center text-text-3">
                                        No lines yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </section>

                {/* US-ORD-02/03 — Shipments: creare, status, „Retry label"/„Discard"/„Mark as shipped". */}
                <ShipmentsSection order={order} canCreateShipment={can.createShipment} workspaceSlug={workspaceSlug} />

                {/* US-BILL-01 (specs.md §12.1) — „Create Invoice" sau link către factura existentă. */}
                <BillingSection orderId={order.id} orderStatus={order.status} workspaceSlug={workspaceSlug} />

                {/* FR-AUD-02, §17.3 — distinct de `OrderTimeline` (evenimente de business
                    dedicate: creat/confirmat/expediat), „History" e strict `activity_log`. */}
                <section aria-label="History" className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">History</h2>
                    <HistoryTab entityType="order" entityId={order.id} />
                </section>
            </div>

            <ConfirmDialog
                open={confirming}
                title="Confirm this order?"
                onClose={() => {
                    setConfirming(false);
                    setBackorderMessage(null);
                }}
                onConfirm={() => submitConfirm(backorderMessage !== null)}
                confirmLabel={backorderMessage !== null ? 'Confirm as backorder' : 'Confirm order'}
                processing={processing}
            >
                {backorderMessage ?? 'This reserves the stock for every line and assigns the order number. This cannot be undone from here — use Cancel afterwards if needed.'}
            </ConfirmDialog>

            <ConfirmDialog
                open={cancelling}
                title="Cancel this order?"
                onClose={() => setCancelling(false)}
                onConfirm={submitCancel}
                confirmLabel="Cancel order"
                confirmVariant="danger"
                processing={processing}
            >
                {order.status === 'confirmed'
                    ? 'This releases the reserved stock on every line. This cannot be undone.'
                    : 'This draft will be cancelled. This cannot be undone.'}
            </ConfirmDialog>

            <ConfirmDialog
                open={confirmingDelete}
                title="Delete this order?"
                onClose={() => setConfirmingDelete(false)}
                onConfirm={destroy}
                confirmLabel="Delete"
                confirmVariant="danger"
                processing={processing}
            >
                This removes the draft permanently. This cannot be undone.
            </ConfirmDialog>
        </>
    );
}

function Field({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div>
            <dt className="text-xs font-medium text-text-3">{label}</dt>
            <dd className="mt-0.5 text-sm text-text">{value}</dd>
        </div>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
