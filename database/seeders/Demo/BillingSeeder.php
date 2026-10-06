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
use Database\Seeders\Support\DemoClock;
use Database\Seeders\Support\DemoId;
use Database\Seeders\Support\Rand;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Facturare către clienți — AR intern (specs.md §12.1), decuplată de starea de onorare.
 * Distribuție ~70% paid / ~20% sent / ~10% overdue (specs.md §21.2), plus mici procente de
 * `draft`/`void` pentru realism.
 */
final class BillingSeeder
{
    /**
     * `VoidInvoiceAction` cere un MOTIV („→ void, din orice stare, cu motiv", §12.1), iar
     * `Invoices/Show.tsx` randează un panou roșu cu el. Seed-ul lăsa coloana goală, deci
     * demo-ul arăta 718 facturi anulate pe care aplicația n-ar fi putut să le producă — și un
     * element de interfață construit, dar invizibil, fiindcă e condiționat pe `voidReason`.
     *
     * @var list<string>
     */
    private const VOID_REASONS = [
        'Duplicate of an invoice already issued for this order.',
        'Issued against the wrong account — reissued to the correct billing entity.',
        'Customer cancelled after invoicing; goods were never shipped.',
        'Pricing error on the source order; corrected invoice issued.',
        'Purchase order number missing — reissued once the customer supplied it.',
        'Billing address changed mid-cycle; replaced with a corrected document.',
        'Order merged into a consolidated monthly invoice.',
        'Tax treatment applied in error; superseded by a corrected invoice.',
    ];

    /** @var array<string, int> */
    private const CREDIT_TERM_DAYS = [
        'net_15' => 15,
        'net_30' => 30,
        'net_60' => 60,
        'prepaid' => 0,
    ];

    /**
     * @param  list<array{id: string, account_id: string, status: string, grand_total: float, currency: string, created_at: int, placed_at: ?int, owner_user_id: string}>  $orders  timestamp-uri Unix, UTC
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
        // 1,1 plăți per factură estimată, nu 0,8: o factură plătită integral se desface în două
        // tranșe în 25% din cazuri, iar o parte dintre cele `sent`/`overdue` au deja o încasare
        // parțială. Măsurat pe setul complet, vechea cifră era cu ~27% sub realitate la toți
        // trei tenanții, deci bara trecea de 100% și își creștea plafonul rând cu rând.
        $paymentWriter = (new ChunkedWriter(Payment::class, 1000, $command, 'Payments', max(1, (int) ($estimatedInvoices * 1.1))))
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

            // Rezumatele poartă timestamp-uri, nu Carbon (vezi StockAndOrdersSeeder — memoria
            // lui `demo:reset`). UTC explicit: aceeași zonă ca `DemoClock` și `app.timezone`,
            // deci `issue_date` iese identic cu varianta pe obiecte.
            $issueDate = Carbon::createFromTimestamp($order['placed_at'] ?? $order['created_at'], 'UTC');
            $dueDate = $issueDate->copy()->addDays($termDays);

            $statusBucket = Rand::weightedKey(['draft' => 3, 'void' => 2, 'paid' => 67, 'sent' => 18, 'overdue' => 10]);

            // Suprascriere de siguranță: „overdue" cere o scadență trecută de cel puțin DOUĂ
            // zile, nu doar trecută. Jobul care marchează restanțele rulează o dată pe zi, deci
            // o factură scadentă acum trei ore n-a fost încă atinsă de el — iar momentul în
            // care AR fi fost marcată (`scadență + 1 zi, 03:00`) ar cădea în viitor, adică n-ar
            // exista un timestamp onest pentru rândul de jurnal. Prins de `DemoSeedScaleTest`
            // în CI, nu local: depinde de ceasul rulării.
            if ($statusBucket === 'overdue' && $dueDate->greaterThan($now->copy()->subDays(2))) {
                $statusBucket = 'sent';
            }

            $total = $order['grand_total'];
            $invoiceId = DemoId::next();
            $voidReason = null;
            $voidedAt = null;

            if ($statusBucket === 'paid') {
                $amountPaid = $total;
                $balanceDue = 0.0;
                $paidStatus = Invoice::STATUS_PAID;
            } elseif ($statusBucket === 'void') {
                $amountPaid = 0.0;
                $balanceDue = 0.0;
                $paidStatus = Invoice::STATUS_VOID;
                $voidReason = self::VOID_REASONS[array_rand(self::VOID_REASONS)];
                $voidedAt = DemoClock::shortlyAfter($issueDate, 24, 24 * 21);
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
            $row['void_reason'] = $voidReason;
            $row['voided_at'] = $voidedAt;
            $row['created_at'] = $issueDate;
            $row['updated_at'] = $issueDate;

            $invoiceWriter->push($row);
            $activityLog->record($tenant->id, $order['owner_user_id'], 'created', Invoice::class, $invoiceId, $issueDate);

            $lastPaidAt = $amountPaid > 0
                ? $this->recordPayments($tenant, $invoiceId, $amountPaid, $issueDate, $termDays, $order['owner_user_id'], $paymentFactory, $paymentWriter, $paidStatus === Invoice::STATUS_PAID)
                : null;

            $this->recordLifecycle($tenant->id, $order['owner_user_id'], $invoiceId, $paidStatus, $issueDate, $dueDate, $lastPaidAt, $voidedAt, $voidReason, $activityLog);
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
    ): ?Carbon {
        $splits = $isFullyPaid && Rand::bool(25) ? 2 : 1;
        $remaining = $amountPaid;
        $last = null;

        for ($n = 1; $n <= $splits; $n++) {
            $amount = $n === $splits ? $remaining : round($amountPaid * (random_int(40, 60) / 100), 2);
            $remaining = round($remaining - $amount, 2);

            // Plafonat la prezent: o factură emisă săptămâna trecută cu termen de 30 de zile
            // ar fi primit altfel o plată datată peste trei săptămâni.
            $paidAt = DemoClock::shortlyAfter($issueDate, 24, 24 * max(2, $termDays + 10));

            $row = $factory->definition();
            $row['id'] = DemoId::next();
            $row['tenant_id'] = $tenant->id;
            $row['invoice_id'] = $invoiceId;
            $row['amount'] = $amount;
            $row['paid_at'] = $paidAt;
            $row['created_by'] = $actorId;

            $writer->push($row);

            // Cele două tranșe se trag INDEPENDENT din interval, deci a doua poate cădea
            // înaintea primei: momentul în care factura devine „plătită" e cel mai TÂRZIU
            // dintre ele, nu ultimul generat.
            if ($last === null || $paidAt->greaterThan($last)) {
                $last = $paidAt;
            }
        }

        return $last;
    }

    /**
     * Istoricul unei facturi, nu doar nașterea ei.
     *
     * Seed-ul scria UN singur rând per factură (`created`), deși le dădea stări reale — în
     * producție, 34.776 de rânduri `created` și 18 `updated` în TOT jurnalul. Efectul: orice
     * factură, plătită sau anulată, avea în tab-ul „History" exact o linie, „Created", în timp
     * ce comenzile aveau 79.000 de tranziții. Tocmai zona pe care un client o deschide ca să
     * vadă cum arată facturarea era cea mai săracă.
     *
     * Tranzițiile sunt cele pe care le-ar fi produs aplicația însăși:
     *  - `draft → sent` — acțiunea „Mark as sent" (singura „editare" pe care o acceptă o
     *    factură draft, `InvoicePolicy::update()`);
     *  - `sent → overdue` — scrisă de `MarkOverdueInvoicesJob`, deci cu actor `null`
     *    („System" în interfață), nu de un om;
     *  - `→ paid` — la momentul ÎNCASĂRII, nu la emitere;
     *  - `→ void` — cu motivul, ca `VoidInvoiceAction`.
     *
     * Forma lui `new_values` e cea pe care o citește `ActivityKind::of()` (`{status: …}`),
     * deci rândurile se citesc „Marked … as paid", nu „Updated Invoice".
     */
    private function recordLifecycle(
        string $tenantId,
        string $actorId,
        string $invoiceId,
        string $status,
        Carbon $issueDate,
        Carbon $dueDate,
        ?Carbon $paidAt,
        ?Carbon $voidedAt,
        ?string $voidReason,
        ActivityLogRecorder $activityLog,
    ): void {
        // O factură rămasă în draft n-a plecat nicăieri: `created` E tot istoricul ei.
        if ($status === Invoice::STATUS_DRAFT) {
            return;
        }

        // Momentul în care jobul zilnic ar fi marcat restanța. Fix, derivat din scadență — nu
        // tras la sorți: asta îl face comparabil cu încasarea.
        $marcareRestanta = $dueDate->copy()->addDay()->startOfDay()->addHours(3);

        // O factură plătită cu întârziere A TRECUT prin „restantă" — jobul o marchează la
        // scadență, independent de faptul că între timp a fost încasată. Comparația e cu
        // MOMENTUL MARCĂRII, nu cu scadența: o factură plătită a doua zi dimineața, înaintea
        // rulării jobului, n-a fost niciodată restantă.
        $aFostRestanta = $status === Invoice::STATUS_OVERDUE
            || ($status === Invoice::STATUS_PAID && $paidAt !== null && $paidAt->greaterThan($marcareRestanta));

        // Fereastră mai strâmtă decât cea a încasărilor (minimum 24 h), ca trimiterea să cadă
        // mereu ÎNAINTEA plății. Pentru o factură care devine restantă trebuie să încapă și
        // înaintea marcării: la termen `prepaid` scadența E ziua emiterii, deci cele până la
        // 20 h ale trimiterii puteau sări peste „ziua următoare, 03:00".
        $sentAt = DemoClock::shortlyAfter($issueDate, 1, 20);

        if ($aFostRestanta && $sentAt->greaterThanOrEqualTo($marcareRestanta)) {
            $sentAt = $marcareRestanta->copy()->subHour();
        }

        $activityLog->record(
            $tenantId, $actorId, 'updated', Invoice::class, $invoiceId, $sentAt,
            ['status' => Invoice::STATUS_DRAFT],
            ['status' => Invoice::STATUS_SENT],
        );

        if ($aFostRestanta) {
            $activityLog->record(
                $tenantId, null, 'updated', Invoice::class, $invoiceId, $marcareRestanta,
                ['status' => Invoice::STATUS_SENT],
                ['status' => Invoice::STATUS_OVERDUE],
            );
        }

        if ($status === Invoice::STATUS_PAID) {
            // `$paidAt` lipsește doar dacă factura e „plătită" cu total zero, deci fără nicio
            // încasare de înregistrat. Rândul tot trebuie scris: altfel ecranul spune „paid",
            // iar istoricul se oprește la „sent".
            $incasatLa = $paidAt ?? DemoClock::shortlyAfter($sentAt, 1, 24 * 10);

            $activityLog->record(
                $tenantId, $actorId, 'updated', Invoice::class, $invoiceId,
                $this->after($incasatLa, $aFostRestanta ? $marcareRestanta : $sentAt),
                ['status' => $aFostRestanta ? Invoice::STATUS_OVERDUE : Invoice::STATUS_SENT],
                ['status' => Invoice::STATUS_PAID],
            );
        }

        if ($status === Invoice::STATUS_VOID && $voidedAt !== null) {
            $activityLog->record(
                $tenantId, $actorId, 'updated', Invoice::class, $invoiceId, $this->after($voidedAt, $sentAt),
                ['status' => Invoice::STATUS_SENT],
                ['status' => Invoice::STATUS_VOID, 'void_reason' => $voidReason],
            );
        }
    }

    /**
     * `DemoClock::shortlyAfter()` plafonează la prezent, deci pentru o factură emisă ieri
     * trimiterea și încasarea pot ieși la ACELAȘI moment, iar istoricul s-ar citi invers.
     * Momentul rândului de jurnal nu e legat de cel al plății din tabela `payments`, deci se
     * poate împinge cu un minut fără să mintă despre bani.
     */
    private function after(Carbon $moment, Carbon $floor): Carbon
    {
        return $moment->greaterThan($floor) ? $moment : $floor->copy()->addMinute();
    }
}
