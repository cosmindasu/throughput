import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button, { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import HistoryTab from '@/Components/History/HistoryTab';
import BillingSection from '@/Components/Invoices/BillingSection';
import OrderTimeline from '@/Components/Orders/OrderTimeline';
import ShipmentsSection from '@/Components/Orders/ShipmentsSection';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import type { OrderStatus, OrdersShowPageProps } from '@/types/generated';

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
    const { t } = useTranslation('orders');
    const locale = useLocale();
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
                        setErrorMessage(Object.values(errors)[0] ?? t('show.confirmDialog.error'));
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
            onError: (errors) => setErrorMessage(Object.values(errors)[0] ?? t('show.cancelDialog.error')),
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
            <Head title={order.orderNumber ?? t('show.orderFallback')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={order.orderNumber ?? t('show.draftTitle', { account: order.account.name })}
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
                            {/* Primitiva `Button`, nu clase repetate de mână: varianta scrisă
                                local nu avea niciun stil de focus (SC 2.4.7 — se vedea doar
                                inelul implicit al browserului, diferit de restul aplicației). */}
                            {can.confirm && (
                                <Button variant="primary" onClick={() => setConfirming(true)}>
                                    {t('show.actions.confirm')}
                                </Button>
                            )}
                            {can.edit && <ButtonLink href={`/${workspaceSlug}/orders/${order.id}/edit`}>{t('show.actions.edit')}</ButtonLink>}
                            {can.cancel && (
                                <Button variant="danger" onClick={() => setCancelling(true)}>
                                    {t('show.actions.cancel')}
                                </Button>
                            )}
                            {can.delete && (
                                <Button variant="danger" onClick={() => setConfirmingDelete(true)}>
                                    {t('show.actions.delete')}
                                </Button>
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
                    <Field label={t('show.fields.grandTotal')} value={<span className="numeric">{formatMoney(order.grandTotal, order.currency, locale)}</span>} />
                    <Field label={t('show.fields.owner')} value={order.owner.name} />
                    <Field
                        label={t('show.fields.contact')}
                        value={order.contact ? (order.contact.isAnonymized ? t('show.fields.contactAnonymized') : order.contact.name) : '—'}
                    />
                    <Field label={t('show.fields.placedAt')} value={order.placedAt ? formatDateTime(order.placedAt, locale) : '—'} />
                    <Field label={t('show.fields.deal')} value={order.deal?.title ?? '—'} />
                    <Field label={t('show.fields.created')} value={order.createdAt ? formatDate(order.createdAt, locale) : '—'} />
                </dl>

                {order.notes && (
                    <section aria-label={t('show.notes.ariaLabel')} className="rounded-lg border border-border bg-surface p-4 text-sm text-text-2">
                        {order.notes}
                    </section>
                )}

                {/* FR-ORD-03 — creat → confirmat → shipment(uri). */}
                <OrderTimeline order={order} />

                <section aria-label={t('show.lines.ariaLabel')} className="overflow-x-auto rounded-lg border border-border bg-surface">
                    <table className="w-full text-left text-sm">
                        {/* Fără heading vizibil propriu deasupra -> cazul implicit, `<caption>`
                            (`.ai/rules/frontend.md`). Era al treilea tipar de nume de tabel din
                            aplicație: niciunul. */}
                        <caption className="sr-only">{t('show.lines.caption')}</caption>
                        <thead>
                            <tr className="border-b border-border-soft text-xs text-text-3">
                                <th scope="col" className="px-4 py-2 font-medium">
                                    {t('show.lines.columns.line')}
                                </th>
                                <th scope="col" className="px-4 py-2 text-right font-medium">
                                    {t('show.lines.columns.quantity')}
                                </th>
                                <th scope="col" className="px-4 py-2 text-right font-medium">
                                    {t('show.lines.columns.fulfilled')}
                                </th>
                                <th scope="col" className="px-4 py-2 text-right font-medium">
                                    {t('show.lines.columns.unitPrice')}
                                </th>
                                <th scope="col" className="px-4 py-2 text-right font-medium">
                                    {t('show.lines.columns.lineTotal')}
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
                                                ? t('show.lines.shipped', { fulfilled: line.quantityFulfilled, total: line.quantity })
                                                : t('show.lines.notYetShipped')}
                                        </span>
                                    </td>
                                    <td className="numeric whitespace-nowrap px-4 py-2 text-right">{formatMoney(line.unitPrice, order.currency, locale)}</td>
                                    <td className="numeric whitespace-nowrap px-4 py-2 text-right">{formatMoney(line.lineTotal, order.currency, locale)}</td>
                                </tr>
                            ))}
                            {order.lines.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-4 py-3 text-center text-text-3">
                                        {t('show.lines.empty')}
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
                <section aria-label={t('show.history.heading')} className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">{t('show.history.heading')}</h2>
                    <HistoryTab entityType="order" entityId={order.id} />
                </section>
            </div>

            <ConfirmDialog
                open={confirming}
                title={t('show.confirmDialog.title')}
                onClose={() => {
                    setConfirming(false);
                    setBackorderMessage(null);
                }}
                onConfirm={() => submitConfirm(backorderMessage !== null)}
                confirmLabel={backorderMessage !== null ? t('show.confirmDialog.confirmAsBackorderLabel') : t('show.confirmDialog.confirmLabel')}
                processing={processing}
            >
                {backorderMessage ?? t('show.confirmDialog.body')}
            </ConfirmDialog>

            <ConfirmDialog
                open={cancelling}
                title={t('show.cancelDialog.title')}
                onClose={() => setCancelling(false)}
                onConfirm={submitCancel}
                confirmLabel={t('show.cancelDialog.confirmLabel')}
                confirmVariant="danger"
                processing={processing}
            >
                {order.status === 'confirmed' ? t('show.cancelDialog.bodyConfirmed') : t('show.cancelDialog.bodyDraft')}
            </ConfirmDialog>

            <ConfirmDialog
                open={confirmingDelete}
                title={t('show.deleteDialog.title')}
                onClose={() => setConfirmingDelete(false)}
                onConfirm={destroy}
                confirmLabel={t('show.deleteDialog.confirmLabel')}
                confirmVariant="danger"
                processing={processing}
            >
                {t('show.deleteDialog.body')}
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
