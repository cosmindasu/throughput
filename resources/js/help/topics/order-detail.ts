import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

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
const orderDetail: HelpTopic = {
    id: 'order-detail',
    title: 'Order detail',
    whatIsThis:
        "This is one order's full record — its account, its lines, its running totals, where it stands (draft, confirmed, partially fulfilled, fulfilled, or cancelled), and every shipment that has gone out against it. Creating and editing a draft use the same fields.",
    whatCanYouDo: [
        'On "New order", pick the "Account" by searching its name, then add lines by searching a variant by SKU or product name and a quantity.',
        'Change the account, contact, notes and lines with "Edit" while the order is still a draft, then "Save changes".',
        '"Confirm order" once the order has at least one line — this locks in an order number and reserves the stock for every line.',
        'Once confirmed, "Create shipment": pick a quantity for each line you can send now — sending less than the full order is fine, the rest stays open for a later shipment.',
        'On a shipment whose label failed, "Retry label" to queue a new attempt, or "Discard" it to free its lines for a new shipment instead.',
        'On a shipment whose label is ready, "Mark as shipped" once it has actually left the warehouse — this is what moves the stock and updates "Shipped X of Y" on each line.',
        '"Cancel" a draft at any time, or a confirmed order as long as nothing has shipped yet.',
    ],
    rules: [
        "An order has no order number while it's a draft — it gets one, sequential for this workspace, only at confirmation, so abandoned drafts never leave a gap in the numbering (BR-ORD-02).",
        'Confirming validates at least one line and reserves that quantity per line; it never touches on-hand stock, since nothing has physically left the warehouse yet — only "reserved" changes.',
        'If a line asks for more than what\'s available, confirming is not silently blocked nor silently allowed: you have to explicitly acknowledge the order will ship as a backorder (BR-STOCK-04), and the server checks that acknowledgement itself, not just the button.',
        "Editing lines only works on a draft — once an order is confirmed, its lines are locked (same rule as an issued invoice), and the only way forward is Cancel.",
        'A shipment never touches stock by itself — creating one only reserves its lines against being claimed by another shipment at the same time. The stock actually leaves the warehouse, and "reserved" only then goes down, at "Mark as shipped".',
        "A quantity on a new shipment can never exceed what's actually left to ship on that line — already-shipped quantity and every other still-open shipment (pending label, failed label, or label ready but not yet shipped) are both subtracted first.",
        'A shipment label is never fetched while you wait: the shipment is created as "Generating label…" and the page updates itself automatically until the carrier responds — with a tracking number and a downloadable label on success, or the carrier\'s own failure reason (never a generic "something went wrong") if it fails.',
        "If there isn't enough stock on hand to cover a shipment when you mark it shipped (a backorder that never arrived), the attempt is refused outright and nothing is recorded — not a partial shipment.",
        'The order moves to "partially fulfilled" the first time any shipment ships without covering every line, and to "fulfilled" only once every line is fully shipped; a later partial shipment on an already partially-fulfilled order simply leaves it there.',
        "A confirmed order can still be cancelled as long as nothing has shipped; that releases the reserved stock. Once at least one shipment exists, only its own unshipped lines can be cancelled — full cancellation is refused (BR-ORD-01).",
        'An Agent can view every order in the workspace, but only edits, confirms, cancels, or creates, retries and discards shipments on the ones they own; Owner and Manager can do all of it on any order.',
    ],
    howItsBuilt: {
        summary:
            "Every legal transition (draft → confirmed, draft/confirmed → cancelled, confirmed → partially_fulfilled → fulfilled) lives in exactly one place, `App\\Enums\\OrderStatus::canTransitionTo()` — so marking a shipment as shipped only ever recomputes the order's status through that same rule, never by writing the enum value directly. The order number is generated by locking the workspace's own row for the duration of the confirmation, the same pattern already used to keep a single \"primary contact\" per account and a single ordering of pipeline stages. Marking a shipment as shipped follows the same discipline one level deeper: it locks the order row first, then every affected stock level in one sorted, all-at-once query — so two shipments racing to ship overlapping variants either wait cleanly on each other or never collide in the first place, and a shipment with too little stock on hand is refused before anything is written, not partway through. The carrier call itself never happens inside a web request: a shipment is saved as \"label pending\" and a background job calls the carrier and writes the result back in its own short step afterwards — the same reason operations elsewhere in the app report their progress instead of making you wait on the spot. The shipping carrier interface (`ShippingCarrier`) and its no-external-call `DemoShippingCarrier` implementation were built as a permanent adapter from day one, not a placeholder, so a real carrier can be added later without changing anything else.",
        adr: {
            id: 'ADR-013',
            title: 'External calls run in a queue, never inside the web request',
            url: adrUrl('ADR-013', 'apeluri-externe-in-cozi'),
        },
    },
};

export default orderDetail;
