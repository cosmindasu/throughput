<?php

namespace App\Http\Controllers\Web\Orders\Shipments;

use App\Actions\Shipments\DiscardShipmentAction;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `DELETE /orders/{order}/shipments/{shipment}` — renunțarea la un shipment
 * `label_failed` (task brief, item 3). Owner/Manager pe orice comandă, Agent doar pe
 * comenzile proprii (decizia proprietarului, Faza 4 — vezi `ShipmentPolicy::discard()`).
 */
final class DiscardShipmentController extends Controller
{
    public function __invoke(Order $order, Shipment $shipment, DiscardShipmentAction $action): RedirectResponse
    {
        // Vezi nota din `RetryShippingLabelController` — `{order}`/`{shipment}` se
        // rezolvă independent, doar scopate la tenant.
        abort_unless($shipment->order_id === $order->getKey(), 404);

        Gate::authorize('discard', $shipment);

        $action->execute($shipment);

        return redirect()->route('orders.show', $order)->with('success', __('flash.orders.shipments.discarded'));
    }
}
