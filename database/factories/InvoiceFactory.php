<?php

namespace Database\Factories;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
            'amount_paid' => 0,
            'balance_due' => 0,
            'pdf_status' => Invoice::PDF_STATUS_READY,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => Invoice::STATUS_PAID]);
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => Invoice::STATUS_SENT]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => ['status' => Invoice::STATUS_OVERDUE]);
    }

    public function void(): static
    {
        return $this->state(fn () => [
            'status' => Invoice::STATUS_VOID,
            'void_reason' => 'Created in error.',
            'voided_at' => now(),
        ]);
    }
}
