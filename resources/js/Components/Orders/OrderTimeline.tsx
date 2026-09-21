import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { formatDateTime } from '@/lib/format';
import type { Order, ShipmentStatus } from '@/types/generated';

const shipmentStepLabels = (t: TFunction): Record<ShipmentStatus, string> => ({
    label_pending: t('timeline.shipmentStatus.label_pending'),
    label_failed: t('timeline.shipmentStatus.label_failed'),
    label_purchased: t('timeline.shipmentStatus.label_purchased'),
    in_transit: t('timeline.shipmentStatus.in_transit'),
    delivered: t('timeline.shipmentStatus.delivered'),
    exception: t('timeline.shipmentStatus.exception'),
});

const SHIPMENT_STEP_TONES: Record<ShipmentStatus, BadgeTone> = {
    label_pending: 'neutral',
    label_failed: 'danger',
    label_purchased: 'accent',
    in_transit: 'accent',
    delivered: 'success',
    exception: 'danger',
};

interface TimelineStep {
    key: string;
    label: string;
    at: string | null;
    tone: BadgeTone;
    reached: boolean;
}

/**
 * FR-ORD-03 — „Timeline vizual al unei comenzi (creat → confirmat → shipment(uri)) pe
 * pagina de detaliu". Facturarea (§12.1) e un pas manual, decuplat de starea de onorare
 * (specs.md §11.2 pas 7) — intenționat absentă de aici, e Faza 5.
 */
export default function OrderTimeline({ order }: { order: Order }) {
    const { t } = useTranslation('orders');
    const locale = useLocale();
    const stepLabels = shipmentStepLabels(t);

    const steps: TimelineStep[] = [
        { key: 'created', label: t('timeline.orderSteps.created'), at: order.createdAt, tone: 'neutral', reached: true },
        { key: 'confirmed', label: t('timeline.orderSteps.confirmed'), at: order.placedAt, tone: 'accent', reached: order.placedAt !== null },
    ];

    // Cele mai vechi shipment-uri primele pe timeline — `order.shipments` vine de la
    // server cele mai recente primele (util pentru secțiunea de management de mai jos).
    for (const shipment of [...order.shipments].reverse()) {
        steps.push({
            key: shipment.id,
            label: stepLabels[shipment.status],
            at: shipment.shippedAt ?? shipment.createdAt,
            tone: SHIPMENT_STEP_TONES[shipment.status],
            reached: true,
        });
    }

    return (
        <ol aria-label={t('timeline.ariaLabel')} className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
            {steps.map((step) => (
                <li key={step.key} className="flex items-center gap-3">
                    <span
                        aria-hidden="true"
                        className={`h-2 w-2 shrink-0 rounded-full ${step.reached ? 'bg-accent-fill' : 'bg-border-soft'}`}
                    />
                    <span className={`text-sm ${step.reached ? 'text-text' : 'text-text-3'}`}>{step.label}</span>
                    {step.at && <StatusBadge tone={step.tone}>{formatDateTime(step.at, locale)}</StatusBadge>}
                </li>
            ))}
        </ol>
    );
}
