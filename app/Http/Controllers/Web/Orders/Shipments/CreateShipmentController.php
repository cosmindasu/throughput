<?php

namespace App\Http\Controllers\Web\Orders\Shipments;

use App\Actions\Shipments\CreateShipmentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\StoreShipmentRequest;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /orders/{order}/shipments` — US-ORD-02/03. Separat de `OrderController`, la fel
 * ca `ConfirmOrderController`/`CancelOrderController`: fiecare tranziție/acțiune de
 * onorare are propriul controller subțire + propriul refuz explicit pe câmp
 * (`ValidationException` din `CreateShipmentAction`, nu un 403 opac).
 */
final class CreateShipmentController extends Controller
{
    public function __invoke(StoreShipmentRequest $request, Order $order, CreateShipmentAction $action): RedirectResponse
    {
        Gate::authorize('create', [Shipment::class, $order]);

        $action->execute($order, $request->quantities());

        return redirect()->route('orders.show', $order)->with('success', 'Shipment created — its label is being generated.');
    }
}
