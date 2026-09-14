<?php

namespace App\Http\Controllers\Web\Orders;

use App\Actions\Orders\CancelOrderAction;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `PATCH /orders/{order}/cancel` — BR-ORD-01, `OrderPolicy::cancel()` (drept +
 * „niciun shipment"), `CancelOrderAction` (starea chiar poate tranziționa acum).
 */
final class CancelOrderController extends Controller
{
    public function __invoke(Order $order, CancelOrderAction $action): RedirectResponse
    {
        Gate::authorize('cancel', $order);

        $cancelled = $action->execute($order);

        return redirect()->route('orders.show', $cancelled)->with('success', 'Order cancelled.');
    }
}
