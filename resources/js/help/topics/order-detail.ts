import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Orders/Show`, `Orders/Create`, `Orders/Edit` — specs.md §11.2/§11.3/§11.4 (US-ORD-01…03),
 * BR-ORD-01/02, BR-STOCK-04, plan §9 (valul 1: draft/confirm/cancel) și plan §9 valul 2
 * (onorare/expediere — shipments, `partially_fulfilled`/`fulfilled`).
 *
 * Reconciliat cu codul la 2026-09-14: `OrderController` (draft + linii), `ConfirmOrderController`
 * (`ConfirmOrderAction`), `CancelOrderController` (`CancelOrderAction`), `App\Http\Controllers\Web\Orders\Shipments\*`
 * (`CreateShipmentAction`, `RetryShippingLabelAction`, `DiscardShipmentAction`,
 * `MarkShipmentShippedAction`), `OrderPolicy`/`ShipmentPolicy` (Agent doar pe comenzile
 * proprii), `App\Enums\OrderStatus` (mașina de stări, un singur loc), `GenerateShippingLabelJob`
 * (ADR-013).
 */
const orderDetail: HelpTopicDefinition = {
    id: 'order-detail',
    adr: {
        id: 'ADR-013',
        title: 'External calls leave the HTTP request and move to queues',
        url: adrUrl('ADR-013', 'apeluri-externe-in-cozi'),
    },
};

export default orderDetail;
