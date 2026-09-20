<?php

namespace App\Http\Controllers\Web\Orders\Shipments;

use App\Actions\Shipments\RetryShippingLabelAction;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `PATCH /orders/{order}/shipments/{shipment}/retry` — US-ORD-03, „reîncercare manuală"
 * pe un shipment `label_failed`.
 */
final class RetryShippingLabelController extends Controller
{
    public function __invoke(Order $order, Shipment $shipment, RetryShippingLabelAction $action): RedirectResponse
    {
        // `{order}`/`{shipment}` se rezolvă independent (ambele scopate doar la tenant
        // curent, RLS) — fără verificarea asta, un shipment al ALTEI comenzi (același
        // tenant) ar trece binding-ul și ar fi acționat prin URL-ul greșit.
        abort_unless($shipment->order_id === $order->getKey(), 404);

        Gate::authorize('retryLabel', $shipment);

        $action->execute($shipment);

        return redirect()->route('orders.show', $order)->with('success', __('flash.orders.shipments.retrying_label'));
    }
}
