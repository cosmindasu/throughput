<?php

namespace App\Http\Controllers\Web\Orders;

use App\Actions\Orders\ConfirmOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\ConfirmOrderRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `PATCH /orders/{order}/confirm` — separat de `OrderController`, la fel cum
 * `DealStageController::move()` e separat de `DealController`: schimbarea de stare are
 * propria regulă de business (`ConfirmOrderAction`) și propriul refuz explicit
 * (`ValidationException` pe `acknowledge_backorder`/`lines`/`status`, nu un 403 opac).
 */
final class ConfirmOrderController extends Controller
{
    public function __invoke(ConfirmOrderRequest $request, Order $order, ConfirmOrderAction $action): RedirectResponse
    {
        Gate::authorize('confirm', $order);

        $confirmed = $action->execute($order, $request->acknowledgesBackorder());

        return redirect()->route('orders.show', $confirmed)->with('success', 'Order confirmed.');
    }
}
