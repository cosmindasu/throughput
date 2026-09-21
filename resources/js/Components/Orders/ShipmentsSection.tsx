import { router, useForm, usePoll } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { controlClass } from '@/Components/Form/Field';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { formatDateTime } from '@/lib/format';
import type { Order, Shipment, ShipmentStatus } from '@/types/generated';

const statusLabels = (t: TFunction): Record<ShipmentStatus, string> => ({
    label_pending: t('shipments.status.label_pending'),
    label_failed: t('shipments.status.label_failed'),
    label_purchased: t('shipments.status.label_purchased'),
    in_transit: t('shipments.status.in_transit'),
    delivered: t('shipments.status.delivered'),
    exception: t('shipments.status.exception'),
});

const STATUS_TONES: Record<ShipmentStatus, BadgeTone> = {
    label_pending: 'neutral',
    label_failed: 'danger',
    label_purchased: 'accent',
    in_transit: 'accent',
    delivered: 'success',
    exception: 'danger',
};

// Statusurile de comandă din care „Create shipment" chiar are șansa să reușească
// (`CreateShipmentAction`) — matches OrderPolicy/`can.createShipment` din server nu
// verifică STAREA (regulă de drept, nu de stare — §7.5), deci UI-ul o adaugă aici ca să
// nu arate un formular care va eșua mereu cu „Shipments can only be created from a
// confirmed or partially fulfilled order".
const SHIPPABLE_ORDER_STATUSES = ['confirmed', 'partially_fulfilled'];

interface ShipmentFormData {
    lines: Record<string, string>;
}

type ConfirmAction = 'discard' | 'markShipped';

/**
 * US-ORD-02/03 — secțiunea „Shipments" de pe `Orders/Show`: formularul de creare (cantități
 * per linie, maxim = rămasul), lista de shipment-uri cu status, „Retry label"/„Discard"/
 * „Mark as shipped", link de tracking și de etichetă. Polling DOAR cât există
 * `label_pending` (Inertia 3, reload parțial pe `order`, ca la `Bulk/Show.tsx`).
 *
 * Două capcane de accesibilitate (code review, găsite de două ori în acest val):
 *  1. Niciun buton nu folosește `disabled` nativ cât timp propria lui acțiune e în curs —
 *     `ConfirmDialog` e deja corectat (`aria-disabled` + `onClick` no-op); butoanele DIN
 *     AFARA lui (Retry, „Create shipment") repetă exact același tipar aici.
 *  2. Declanșatorul unui buton poate DISPĂREA la succes (statusul shipment-ului se
 *     schimbă, ex. `label_failed` → `label_pending` după „Retry"; discard elimină rândul
 *     complet) — elementul focusat dispare din DOM și browserul mută focusul pe `<body>`.
 *     Focusul se mută explicit pe un element STABIL: rândul shipment-ului (`tabIndex={-1}`)
 *     dacă mai există, altfel titlul secțiunii.
 */
export default function ShipmentsSection({
    order,
    canCreateShipment,
    workspaceSlug,
}: {
    order: Order;
    canCreateShipment: boolean;
    workspaceSlug: string;
}) {
    const { t } = useTranslation('orders');
    const locale = useLocale();
    const labels = statusLabels(t);
    const hasPendingLabel = order.shipments.some((shipment) => shipment.status === 'label_pending');
    // Defect real găsit la auditul E2E (raportul pachetului) — `usePoll` pornește
    // polling-ul într-un `useEffect` cu dependențe GOALE (`@inertiajs/react`, citit direct
    // în sursă): `autoStart` se evaluează O SINGURĂ DATĂ, la montare. `ShipmentsSection`
    // montează ÎMPREUNĂ cu `Orders/Show`, ÎNAINTE să existe vreun shipment — la acel
    // moment `hasPendingLabel` e mereu `false`, deci `autoStart: hasPendingLabel` îngheață
    // polling-ul PERMANENT oprit. Fără `start()` simetric aici, un shipment creat DUPĂ
    // montare (fluxul normal — „Create shipment" se apasă pe o pagină deja deschisă)
    // rămânea pe „Generating label…" la nesfârșit, chiar dacă eticheta se cumpăra corect
    // pe server: clientul nu mai cerea niciodată pagina din nou.
    const { start, stop } = usePoll(3000, { only: ['order'] }, { autoStart: hasPendingLabel });

    useEffect(() => {
        if (hasPendingLabel) {
            start();
        } else {
            stop();
        }
    }, [hasPendingLabel, start, stop]);

    const [confirmTarget, setConfirmTarget] = useState<{ action: ConfirmAction; shipment: Shipment } | null>(null);
    const [rowProcessingId, setRowProcessingId] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);
    const [confirmError, setConfirmError] = useState<string | null>(null);

    const headingId = useId();
    const headingRef = useRef<HTMLHeadingElement>(null);
    const rowRefs = useRef(new Map<string, HTMLLIElement>());

    /** Element STABIL de focalizat după o acțiune reușită — rândul, dacă mai există. */
    const focusRowOrHeading = (shipmentId: string) => {
        requestAnimationFrame(() => {
            (rowRefs.current.get(shipmentId) ?? headingRef.current)?.focus();
        });
    };

    const { data, setData, post, processing, errors: typedErrors, transform, reset } = useForm<ShipmentFormData>({ lines: {} });

    // `CreateShipmentAction` poate întoarce și `status` (comanda nu mai e confirmed/
    // partially_fulfilled), o cheie din afara formei `ShipmentFormData` — `FormDataErrors`
    // e generic pe formă, deci indexarea liberă are nevoie de un tip mai larg aici, o
    // singură dată.
    const errors = typedErrors as Record<string, string | undefined>;

    const shippableLines = order.lines.filter((line) => (line.remainingToShip ?? 0) > 0);
    const canShowCreateForm = canCreateShipment && SHIPPABLE_ORDER_STATUSES.includes(order.status) && shippableLines.length > 0;

    const submitCreate = (event: FormEvent) => {
        event.preventDefault();

        // Butonul „Create shipment" e `aria-disabled`, NU `disabled` nativ, cât `processing`
        // e adevărat (rămâne focusabil) — deci un al doilea `submit` (Enter repetat, dublu
        // clic) trebuie oprit AICI, nu de browser.
        if (processing) {
            return;
        }

        setActionError(null);

        transform((formData) => ({
            lines: Object.fromEntries(
                Object.entries(formData.lines)
                    .map(([orderLineId, quantity]) => [orderLineId, Number(quantity)] as const)
                    .filter(([, quantity]) => Number.isInteger(quantity) && quantity > 0),
            ),
        }));

        post(`/${workspaceSlug}/orders/${order.id}/shipments`, {
            preserveScroll: true,
            onSuccess: () => {
                reset('lines');
                requestAnimationFrame(() => headingRef.current?.focus());
            },
        });
    };

    const retry = (shipment: Shipment) => {
        if (rowProcessingId === shipment.id) {
            return;
        }

        setRowProcessingId(shipment.id);
        setActionError(null);

        router.patch(
            `/${workspaceSlug}/orders/${order.id}/shipments/${shipment.id}/retry`,
            {},
            {
                onSuccess: () => focusRowOrHeading(shipment.id),
                onError: (formErrors) => setActionError(Object.values(formErrors)[0] ?? t('shipments.errors.retryFailed')),
                onFinish: () => setRowProcessingId(null),
            },
        );
    };

    const openConfirm = (action: ConfirmAction, shipment: Shipment) => {
        if (rowProcessingId === shipment.id) {
            return;
        }

        setConfirmError(null);
        setConfirmTarget({ action, shipment });
    };

    const runConfirmedAction = () => {
        if (!confirmTarget || rowProcessingId === confirmTarget.shipment.id) {
            return;
        }

        const { action, shipment } = confirmTarget;
        setRowProcessingId(shipment.id);
        setConfirmError(null);

        const onSuccess = () => {
            setConfirmTarget(null);
            focusRowOrHeading(shipment.id);
        };

        // La eroare, dialogul NU se închide tăcut (code review): rămâne deschis, cu
        // mesajul serverului vizibil chiar în el (`role="alert"`, deja în arborele
        // accesibil al modalului — o bară în restul paginii n-ar fi nici văzută, nici
        // anunțată cât `<dialog>` e modal). Utilizatorul poate încerca din nou sau
        // renunța explicit cu „Cancel".
        const onError = (formErrors: Record<string, string>) =>
            setConfirmError(Object.values(formErrors)[0] ?? t('shipments.errors.actionFailed'));

        const onFinish = () => setRowProcessingId(null);

        if (action === 'discard') {
            router.delete(`/${workspaceSlug}/orders/${order.id}/shipments/${shipment.id}`, { onSuccess, onError, onFinish });
        } else {
            router.patch(`/${workspaceSlug}/orders/${order.id}/shipments/${shipment.id}/ship`, {}, { onSuccess, onError, onFinish });
        }
    };

    return (
        <section aria-label={t('shipments.heading')} className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
            <h2
                id={headingId}
                ref={headingRef}
                tabIndex={-1}
                className="text-sm font-semibold text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                {t('shipments.heading')}
            </h2>

            {actionError && (
                <p role="alert" className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                    {actionError}
                </p>
            )}

            {canShowCreateForm && (
                <form onSubmit={submitCreate} className="flex flex-col gap-3 rounded-md border border-border-soft p-3">
                    <p className="text-xs text-text-3">{t('shipments.form.hint')}</p>

                    {(errors.lines || errors.status) && (
                        <p role="alert" className="text-xs text-danger">
                            {errors.lines ?? errors.status}
                        </p>
                    )}

                    <div className="overflow-x-auto rounded-md border border-border-soft">
                        <table className="w-full text-left text-sm">
                            {/* Headingul de deasupra („Shipments") e al SECȚIUNII, nu al acestui
                                tabel — deci cazul implicit, `<caption>` (`.ai/rules/frontend.md`). */}
                            <caption className="sr-only">{t('shipments.form.table.caption')}</caption>
                            <thead>
                                <tr className="border-b border-border-soft text-xs text-text-3">
                                    <th scope="col" className="px-3 py-2 font-medium">
                                        {t('shipments.form.table.line')}
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-right font-medium">
                                        {t('shipments.form.table.remaining')}
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-right font-medium">
                                        {t('shipments.form.table.quantityToShip')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {order.lines.map((line) => {
                                    const remaining = line.remainingToShip ?? 0;
                                    const lineError = errors[`lines.${line.id}`];
                                    const lineErrorId = `${headingId}-line-${line.id}-error`;

                                    return (
                                        <tr key={line.id} className="border-b border-border-soft last:border-b-0">
                                            <td className="px-3 py-2">{line.description}</td>
                                            <td className="numeric px-3 py-2 text-right">{remaining}</td>
                                            <td className="px-3 py-2 text-right">
                                                {remaining > 0 ? (
                                                    <label className="flex flex-col items-end gap-1">
                                                        <span className="sr-only">{t('shipments.form.quantityLabel', { description: line.description })}</span>
                                                        <input
                                                            type="number"
                                                            min={0}
                                                            max={remaining}
                                                            step={1}
                                                            value={data.lines[line.id] ?? ''}
                                                            onChange={(event) =>
                                                                setData('lines', { ...data.lines, [line.id]: event.target.value })
                                                            }
                                                            aria-invalid={lineError ? true : undefined}
                                                            aria-describedby={lineError ? lineErrorId : undefined}
                                                            className={`${controlClass} numeric w-24 text-right`}
                                                        />
                                                        {lineError && (
                                                            <span id={lineErrorId} role="alert" className="text-xs text-danger">
                                                                {lineError}
                                                            </span>
                                                        )}
                                                    </label>
                                                ) : (
                                                    <span className="text-text-3">—</span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <Button
                            type="submit"
                            variant="primary"
                            aria-disabled={processing || undefined}
                            className={processing ? 'cursor-not-allowed opacity-60' : ''}
                        >
                            {processing ? t('shipments.form.submitPending') : t('shipments.form.submit')}
                        </Button>
                    </div>
                </form>
            )}

            {order.shipments.length === 0 ? (
                <p className="text-sm text-text-3">{t('shipments.empty')}</p>
            ) : (
                <ul className="flex flex-col gap-3">
                    {order.shipments.map((shipment, index) => {
                        const isRowProcessing = rowProcessingId === shipment.id;
                        // SC 2.4.4 / 4.1.2 — o comandă poate avea mai multe expedieri, fiecare
                        // cu aceleași patru acțiuni: fără discriminator, „Retry label" ×3 sunt
                        // indistinctibile în lista de butoane a unui cititor de ecran. Numărul
                        // de urmărire când există (ce citește și omul), altfel poziția în listă.
                        const shipmentName = shipment.trackingNumber ?? `${index + 1}`;

                        return (
                            <li
                                key={shipment.id}
                                ref={(el) => {
                                    if (el) {
                                        rowRefs.current.set(shipment.id, el);
                                    } else {
                                        rowRefs.current.delete(shipment.id);
                                    }
                                }}
                                tabIndex={-1}
                                className="flex flex-col gap-2 rounded-md border border-border-soft p-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge tone={STATUS_TONES[shipment.status]}>{labels[shipment.status]}</StatusBadge>
                                    <span className="text-xs text-text-3">
                                        {shipment.carrier}
                                        {shipment.serviceLevel ? ` · ${shipment.serviceLevel}` : ''}
                                    </span>
                                    {shipment.createdAt && (
                                        <span className="text-xs text-text-3">
                                            {t('shipments.createdAt', { date: formatDateTime(shipment.createdAt, locale) })}
                                        </span>
                                    )}
                                </div>

                                <ul className="text-xs text-text-2">
                                    {shipment.lines.map((line) => (
                                        <li key={line.id}>
                                            {line.quantity} × {line.description ?? 'line'}
                                        </li>
                                    ))}
                                </ul>

                                {shipment.status === 'label_failed' && shipment.errorMessage && (
                                    <p role="alert" className="text-xs text-danger">
                                        {shipment.errorMessage}
                                    </p>
                                )}

                                {shipment.trackingNumber && (
                                    <p className="numeric text-xs text-text-2">
                                        {t('shipments.trackingLabel')}{' '}
                                        {shipment.trackingUrl ? (
                                            <a
                                                href={shipment.trackingUrl}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-accent-text hover:underline"
                                            >
                                                {shipment.trackingNumber}
                                            </a>
                                        ) : (
                                            shipment.trackingNumber
                                        )}
                                    </p>
                                )}

                                <div className="flex flex-wrap gap-2">
                                    {shipment.labelUrl && (
                                        <a
                                            href={shipment.labelUrl}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-xs font-medium text-accent-text hover:underline"
                                        >
                                            {t('shipments.downloadLabel')}
                                            <span className="sr-only"> {t('shipments.forShipment', { name: shipmentName })}</span>
                                        </a>
                                    )}

                                    {shipment.status === 'label_failed' && shipment.can.retryLabel && (
                                        <Button
                                            onClick={() => retry(shipment)}
                                            aria-disabled={isRowProcessing || undefined}
                                            className={isRowProcessing ? 'cursor-not-allowed opacity-60' : ''}
                                        >
                                            {isRowProcessing ? t('shipments.retryingLabel') : t('shipments.retryLabel')}
                                            <span className="sr-only"> {t('shipments.forShipment', { name: shipmentName })}</span>
                                        </Button>
                                    )}

                                    {shipment.status === 'label_failed' && shipment.can.discard && (
                                        <Button
                                            variant="danger"
                                            onClick={() => openConfirm('discard', shipment)}
                                            aria-disabled={isRowProcessing || undefined}
                                            className={isRowProcessing ? 'cursor-not-allowed opacity-60' : ''}
                                        >
                                            {t('shipments.discard')}<span className="sr-only"> {t('shipments.discardShipment', { name: shipmentName })}</span>
                                        </Button>
                                    )}

                                    {shipment.status === 'label_purchased' && shipment.can.markShipped && (
                                        <Button
                                            variant="primary"
                                            onClick={() => openConfirm('markShipped', shipment)}
                                            aria-disabled={isRowProcessing || undefined}
                                            className={isRowProcessing ? 'cursor-not-allowed opacity-60' : ''}
                                        >
                                            {t('shipments.markShipped')}<span className="sr-only"> {t('shipments.markShippedDash', { name: shipmentName })}</span>
                                        </Button>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}

            <ConfirmDialog
                open={confirmTarget !== null}
                title={confirmTarget?.action === 'discard' ? t('shipments.discardDialog.title') : t('shipments.markShippedDialog.title')}
                onClose={() => {
                    setConfirmTarget(null);
                    setConfirmError(null);
                }}
                onConfirm={runConfirmedAction}
                confirmLabel={confirmTarget?.action === 'discard' ? t('shipments.discardDialog.confirmLabel') : t('shipments.markShippedDialog.confirmLabel')}
                confirmVariant={confirmTarget?.action === 'discard' ? 'danger' : 'primary'}
                processing={rowProcessingId === confirmTarget?.shipment.id}
            >
                {confirmError && (
                    <p role="alert" className="mb-2 rounded-md bg-danger-tint px-2 py-1.5 text-danger">
                        {confirmError}
                    </p>
                )}
                {confirmTarget?.action === 'discard' ? t('shipments.discardDialog.body') : t('shipments.markShippedDialog.body')}
            </ConfirmDialog>
        </section>
    );
}
