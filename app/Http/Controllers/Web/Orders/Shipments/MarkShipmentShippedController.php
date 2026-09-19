<?php

namespace App\Http\Controllers\Web\Orders\Shipments;

use App\Actions\Shipments\MarkShipmentShippedAction;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `PATCH /orders/{order}/shipments/{shipment}/ship` — §11.2 pas 5-6. Mișcarea de stoc și
 * tranziția comenzii trăiesc în `MarkShipmentShippedAction`; controllerul doar
 * autorizează și traduce `ValidationException` (backorder nerecepționat, stare
 * ilegală) în eroarea de câmp standard Inertia.
 */
final class MarkShipmentShippedController extends Controller
{
    public function __invoke(Request $request, Order $order, Shipment $shipment, MarkShipmentShippedAction $action): RedirectResponse
    {
        // Vezi nota din `RetryShippingLabelController` — `{order}`/`{shipment}` se
        // rezolvă independent, doar scopate la tenant.
        abort_unless($shipment->order_id === $order->getKey(), 404);

        Gate::authorize('markShipped', $shipment);

        $action->execute($shipment, $request->user());

        return redirect()->route('orders.show', $order)->with('success', 'Shipment marked as shipped.');
    }
}
