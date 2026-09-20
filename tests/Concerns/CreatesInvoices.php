<?php

namespace Tests\Concerns;

use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;

/**
 * Fixtură minimă pentru testele lotului „Facturare" (specs.md §12.1, plan §11).
 *
 * Fișier NOU, doar pentru testele acestui pachet — la fel ca `Tests\Concerns\CreatesOrders`
 * (Faza 3): nu extinde `Tests\TestCase` și nu atinge niciun fișier comun.
 *
 * Comenzile de aici SUNT create direct `confirmed`/`fulfilled`, fără să treacă prin
 * `ConfirmOrderAction` — facturarea e decuplată de onorare (§11.2 pas 7) și nu are
 * nevoie de stoc/rezervări reale pentru a fi testată. Apelanții rulează asta ÎN
 * `TenantContext::run()`, la fel ca `CreatesOrders`.
 */
trait CreatesInvoices
{
    protected function confirmedOrder(
        Account $account,
        User $owner,
        float $grandTotal = 500.0,
        string $currency = 'USD',
        ?string $orderNumber = null,
        OrderStatus $status = OrderStatus::Confirmed,
    ): Order {
        $order = new Order([
            'account_id' => $account->getKey(),
            'owner_user_id' => $owner->getKey(),
            'order_number' => $orderNumber ?? 'ORD-'.random_int(100000, 999999),
            'status' => $status,
            'currency' => $currency,
            'subtotal' => $grandTotal,
            'discount_total' => 0,
            'shipping_total' => 0,
            'grand_total' => $grandTotal,
            'placed_at' => now(),
        ]);
        $order->created_by = $owner->getKey();
        $order->save();

        return $order;
    }

    protected function sentInvoice(Order $order, float $total, float $amountPaid = 0.0): Invoice
    {
        $invoice = new Invoice([
            'order_id' => $order->getKey(),
            'invoice_number' => 'INV-'.random_int(100000, 999999),
            'status' => Invoice::STATUS_SENT,
            'issue_date' => now()->subDays(5)->toDateString(),
            'due_date' => now()->addDays(25)->toDateString(),
            'currency' => $order->currency,
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'amount_paid' => $amountPaid,
            'balance_due' => round($total - $amountPaid, 2),
            'pdf_status' => Invoice::PDF_STATUS_READY,
        ]);
        $invoice->save();

        return $invoice;
    }
}
