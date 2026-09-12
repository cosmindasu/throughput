<?php

namespace Database\Seeders\Demo;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use Database\Factories\InvoiceFactory;
use Database\Factories\PaymentFactory;
use Database\Seeders\Support\ActivityLogRecorder;
use Database\Seeders\Support\ChunkedWriter;
use Database\Seeders\Support\Rand;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Facturare către clienți — AR intern (specs.md §12.1), decuplată de starea de onorare.
 * Distribuție ~70% paid / ~20% sent / ~10% overdue (specs.md §21.2), plus mici procente de
 * `draft`/`void` pentru realism.
 */
final class BillingSeeder
{
    /** @var array<string, int> */
    private const CREDIT_TERM_DAYS = [
        'net_15' => 15,
        'net_30' => 30,
        'net_60' => 60,
        'prepaid' => 0,
    ];

    /**
     * @param  list<array{id: string, account_id: string, status: string, grand_total: float, currency: string, created_at: Carbon, placed_at: ?Carbon, owner_user_id: string}>  $orders
     * @param  array{accounts: list<array{id: string, credit_terms: string}>, contacts_by_account: array<string, list<string>>}  $accountsResult
     */
    public function run(Tenant $tenant, array $config, array $orders, array $accountsResult, ?Command $command, ActivityLogRecorder $activityLog): void
    {
        $creditTermsByAccount = [];
        foreach ($accountsResult['accounts'] as $account) {
            $creditTermsByAccount[$account['id']] = $account['credit_terms'];
        }

        $eligible = array_values(array_filter($orders, fn ($o) => $o['status'] !== Order::STATUS_DRAFT && $o['status'] !== Order::STATUS_CANCELLED));
        $estimatedInvoices = (int) (count($eligible) * 0.7);

        $invoiceWriter = new ChunkedWriter(Invoice::class, 1000, $command, 'Invoices', max(1, $estimatedInvoices));
        $paymentWriter = (new ChunkedWriter(Payment::class, 1000, $command, 'Payments', max(1, (int) ($estimatedInvoices * 0.8))))
            ->dependsOn($invoiceWriter);   // FK payments.invoice_id

        $invoiceFactory = new InvoiceFactory;
        $paymentFactory = new PaymentFactory;

        $invoiceNumber = 5000;
        $now = Carbon::now();

        foreach ($eligible as $order) {
            $probability = match ($order['status']) {
                Order::STATUS_FULFILLED => 95,
                Order::STATUS_PARTIALLY_FULFILLED => 70,
                default => 40, // confirmed
            };

            if (! Rand::bool($probability)) {
                continue;
            }

            $creditTerms = $creditTermsByAccount[$order['account_id']] ?? 'net_30';
            $termDays = self::CREDIT_TERM_DAYS[$creditTerms] ?? 30;

            $issueDate = ($order['placed_at'] ?? $order['created_at'])->copy();
            $dueDate = $issueDate->copy()->addDays($termDays);

            $statusBucket = Rand::weightedKey(['draft' => 3, 'void' => 2, 'paid' => 67, 'sent' => 18, 'overdue' => 10]);

            // Suprascriere de siguranță: "overdue" cere `due_date` deja trecut — o comandă
            // recentă cu termen lung nu poate fi încă restantă.
            if ($statusBucket === 'overdue' && $dueDate->greaterThan($now)) {
                $statusBucket = 'sent';
            }

            $total = $order['grand_total'];
            $invoiceId = (string) Str::ulid();

            if ($statusBucket === 'paid') {
                $amountPaid = $total;
                $balanceDue = 0.0;
                $paidStatus = Invoice::STATUS_PAID;
            } elseif ($statusBucket === 'void') {
                $amountPaid = 0.0;
                $balanceDue = 0.0;
                $paidStatus = Invoice::STATUS_VOID;
            } elseif ($statusBucket === 'draft') {
                $amountPaid = 0.0;
                $balanceDue = $total;
                $paidStatus = Invoice::STATUS_DRAFT;
            } else {
                // sent / overdue — ~15% au o încasare parțială deja înregistrată.
                if (Rand::bool(15)) {
                    $amountPaid = Rand::money($total * 0.2, $total * 0.6);
                    $balanceDue = round($total - $amountPaid, 2);
                } else {
                    $amountPaid = 0.0;
                    $balanceDue = $total;
                }
                $paidStatus = $statusBucket;
            }

            $row = $invoiceFactory->definition();
            $row['id'] = $invoiceId;
            $row['tenant_id'] = $tenant->id;
            $row['order_id'] = $order['id'];
            $row['invoice_number'] = "{$config['code']}-INV-".($invoiceNumber++);
            $row['status'] = $paidStatus;
            $row['issue_date'] = $issueDate->toDateString();
            $row['due_date'] = $dueDate->toDateString();
            $row['currency'] = $order['currency'];
            $row['subtotal'] = $total;
            $row['tax_total'] = 0;
            $row['total'] = $total;
            $row['amount_paid'] = $amountPaid;
            $row['balance_due'] = $balanceDue;
            $row['pdf_status'] = Rand::weightedKey(['ready' => 90, 'pending' => 7, 'failed' => 3]);
            $row['created_at'] = $issueDate;
            $row['updated_at'] = $issueDate;

            $invoiceWriter->push($row);
            $activityLog->record($tenant->id, $order['owner_user_id'], 'created', Invoice::class, $invoiceId, $issueDate);

            if ($amountPaid > 0) {
                $this->recordPayments($tenant, $invoiceId, $amountPaid, $issueDate, $termDays, $order['owner_user_id'], $paymentFactory, $paymentWriter, $paidStatus === Invoice::STATUS_PAID);
            }
        }

        $invoiceWriter->flush();
        $paymentWriter->flush();
        $activityLog->flush();
    }

    private function recordPayments(
        Tenant $tenant,
        string $invoiceId,
        float $amountPaid,
        Carbon $issueDate,
        int $termDays,
        string $actorId,
        PaymentFactory $factory,
        ChunkedWriter $writer,
        bool $isFullyPaid,
    ): void {
        $splits = $isFullyPaid && Rand::bool(25) ? 2 : 1;
        $remaining = $amountPaid;

        for ($n = 1; $n <= $splits; $n++) {
            $amount = $n === $splits ? $remaining : round($amountPaid * (random_int(40, 60) / 100), 2);
            $remaining = round($remaining - $amount, 2);

            $paidAt = $issueDate->copy()->addDays(random_int(1, max(2, $termDays + 10)));

            $row = $factory->definition();
            $row['id'] = (string) Str::ulid();
            $row['tenant_id'] = $tenant->id;
            $row['invoice_id'] = $invoiceId;
            $row['amount'] = $amount;
            $row['paid_at'] = $paidAt;
            $row['created_by'] = $actorId;

            $writer->push($row);
        }
    }
}
