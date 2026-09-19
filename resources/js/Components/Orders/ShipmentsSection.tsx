import { router, useForm, usePoll } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import Button from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { controlClass } from '@/Components/Form/Field';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import type { Order, Shipment, ShipmentStatus } from '@/types/generated';

const dateTimeFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short' });

const STATUS_LABELS: Record<ShipmentStatus, string> = {
    label_pending: 'Generating label…',
    label_failed: 'Label failed',
    label_purchased: 'Label ready',
    in_transit: 'In transit',
    delivered: 'Delivered',
    exception: 'Exception',
};

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
    const hasPendingLabel = order.shipments.some((shipment) => shipment.status === 'label_pending');
    const { stop } = usePoll(3000, { only: ['order'] }, { autoStart: hasPendingLabel });

    useEffect(() => {
        if (!hasPendingLabel) {
            stop();
        }
    }, [hasPendingLabel, stop]);

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
                onError: (formErrors) => setActionError(Object.values(formErrors)[0] ?? 'This label could not be retried.'),
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
            setConfirmError(Object.values(formErrors)[0] ?? 'This action could not be completed.');

        const onFinish = () => setRowProcessingId(null);

        if (action === 'discard') {
            router.delete(`/${workspaceSlug}/orders/${order.id}/shipments/${shipment.id}`, { onSuccess, onError, onFinish });
        } else {
            router.patch(`/${workspaceSlug}/orders/${order.id}/shipments/${shipment.id}/ship`, {}, { onSuccess, onError, onFinish });
        }
    };

    return (
        <section aria-label="Shipments" className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
            <h2
                id={headingId}
                ref={headingRef}
                tabIndex={-1}
                className="text-sm font-semibold text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                Shipments
            </h2>

            {actionError && (
                <p role="alert" className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                    {actionError}
                </p>
            )}

            {canShowCreateForm && (
                <form onSubmit={submitCreate} className="flex flex-col gap-3 rounded-md border border-border-soft p-3">
                    <p className="text-xs text-text-3">Choose a quantity for each line to include — leave the rest at 0.</p>

                    {(errors.lines || errors.status) && (
                        <p role="alert" className="text-xs text-danger">
                            {errors.lines ?? errors.status}
                        </p>
                    )}

                    <div className="overflow-x-auto rounded-md border border-border-soft">
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-border-soft text-xs text-text-3">
                                    <th scope="col" className="px-3 py-2 font-medium">
                                        Line
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-right font-medium">
                                        Remaining
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-right font-medium">
                                        Quantity to ship
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
                                                        <span className="sr-only">Quantity to ship for {line.description}</span>
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
                            {processing ? 'Creating…' : 'Create shipment'}
                        </Button>
                    </div>
                </form>
            )}

            {order.shipments.length === 0 ? (
                <p className="text-sm text-text-3">No shipments yet.</p>
            ) : (
                <ul className="flex flex-col gap-3">
                    {order.shipments.map((shipment) => {
                        const isRowProcessing = rowProcessingId === shipment.id;

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
                                    <StatusBadge tone={STATUS_TONES[shipment.status]}>{STATUS_LABELS[shipment.status]}</StatusBadge>
                                    <span className="text-xs text-text-3">
                                        {shipment.carrier}
                                        {shipment.serviceLevel ? ` · ${shipment.serviceLevel}` : ''}
                                    </span>
                                    {shipment.createdAt && (
                                        <span className="text-xs text-text-3">
                                            Created {dateTimeFormatter.format(new Date(shipment.createdAt))}
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
                                        Tracking:{' '}
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
                                            Download label
                                        </a>
                                    )}

                                    {shipment.status === 'label_failed' && shipment.can.retryLabel && (
                                        <Button
                                            onClick={() => retry(shipment)}
                                            aria-disabled={isRowProcessing || undefined}
                                            className={isRowProcessing ? 'cursor-not-allowed opacity-60' : ''}
                                        >
                                            {isRowProcessing ? 'Retrying…' : 'Retry label'}
                                        </Button>
                                    )}

                                    {shipment.status === 'label_failed' && shipment.can.discard && (
                                        <Button
                                            variant="danger"
                                            onClick={() => openConfirm('discard', shipment)}
                                            aria-disabled={isRowProcessing || undefined}
                                            className={isRowProcessing ? 'cursor-not-allowed opacity-60' : ''}
                                        >
                                            Discard
                                        </Button>
                                    )}

                                    {shipment.status === 'label_purchased' && shipment.can.markShipped && (
                                        <Button
                                            variant="primary"
                                            onClick={() => openConfirm('markShipped', shipment)}
                                            aria-disabled={isRowProcessing || undefined}
                                            className={isRowProcessing ? 'cursor-not-allowed opacity-60' : ''}
                                        >
                                            Mark as shipped
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
                title={confirmTarget?.action === 'discard' ? 'Discard this shipment?' : 'Mark this shipment as shipped?'}
                onClose={() => {
                    setConfirmTarget(null);
                    setConfirmError(null);
                }}
                onConfirm={runConfirmedAction}
                confirmLabel={confirmTarget?.action === 'discard' ? 'Discard' : 'Mark as shipped'}
                confirmVariant={confirmTarget?.action === 'discard' ? 'danger' : 'primary'}
                processing={rowProcessingId === confirmTarget?.shipment.id}
            >
                {confirmError && (
                    <p role="alert" className="mb-2 rounded-md bg-danger-tint px-2 py-1.5 text-danger">
                        {confirmError}
                    </p>
                )}
                {confirmTarget?.action === 'discard'
                    ? 'This removes the shipment and frees its lines for a new one. This cannot be undone.'
                    : 'This records the stock leaving the warehouse (on hand and reserved both decrease) and updates the order lines. This cannot be undone.'}
            </ConfirmDialog>
        </section>
    );
}
