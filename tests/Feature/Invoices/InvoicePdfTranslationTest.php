<?php

namespace Tests\Feature\Invoices;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\OrderLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\ProductFactory;
use Database\Factories\VariantFactory;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Tests\Concerns\CreatesInvoices;
use Tests\TestCase;

/**
 * FR-I18N-04, ADR-022 — textul fix din `resources/views/invoices/pdf/invoice.blade.php`
 * (catalog `lang/{en,fr}/pdf.php`, namespace `pdf.invoice.*`). Randează șablonul DIRECT
 * (`View::make(...)->render()`), fără `GenerateInvoicePdfJob` (afara perimetrului acestui
 * lot) — fixtura eager-load-uiește exact relațiile pe care jobul le-ar fi încărcat
 * (`order.account`, `order.contact`, `order.orderLines.variant.product`, `tenant`), ca
 * șablonul să vadă aceleași date, indiferent de cine îl randează.
 */
class InvoicePdfTranslationTest extends TestCase
{
    use CreatesInvoices;

    private Tenant $tenant;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->tenant, function (): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
        });
    }

    public function test_status_and_labels_translate_to_french(): void
    {
        $invoice = $this->invoiceWithOneLine();

        App::setLocale('fr');
        $html = View::make('invoices.pdf.invoice', ['invoice' => $invoice])->render();
        App::setLocale('en');

        $this->assertStringContainsString('<html lang="fr">', $html);
        // `Invoice::STATUS_SENT` = 'sent' → catalog `pdf.invoice.status.sent` = 'Envoyée'.
        $this->assertStringContainsString('ENVOYÉE', $html);
        $this->assertStringContainsString('Facturé à', $html);
        // Apostroful iese HTML-escaped din `{{ }}` Blade (`&#039;`), nu literal.
        $this->assertStringContainsString('Date d&#039;émission', $html);
        $this->assertStringContainsString('Quantité', $html);
        $this->assertStringContainsString('Sous-total', $html);
        $this->assertStringContainsString('Solde dû', $html);
    }

    public function test_status_and_labels_stay_in_english_by_default(): void
    {
        $invoice = $this->invoiceWithOneLine();

        App::setLocale('en');
        $html = View::make('invoices.pdf.invoice', ['invoice' => $invoice])->render();

        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('SENT', $html);
        $this->assertStringContainsString('Bill to', $html);
        $this->assertStringContainsString('Balance due', $html);
    }

    /**
     * Comanda-sursă fără linii (`orderLines` gol) — mesajul de tabel gol trece prin
     * catalog, la fel ca la export/raport (`pdf.invoice.no_lines`).
     */
    public function test_no_lines_message_translates_to_french(): void
    {
        $invoiceId = TenantContext::run($this->tenant, function (): string {
            $order = $this->confirmedOrder($this->account, $this->owner, 100);

            return $this->sentInvoice($order, 100)->getKey();
        });

        $invoice = TenantContext::run(
            $this->tenant,
            fn () => Invoice::query()
                ->with(['order.account', 'order.contact', 'order.orderLines.variant.product', 'tenant'])
                ->find($invoiceId)
        );

        App::setLocale('fr');
        $html = View::make('invoices.pdf.invoice', ['invoice' => $invoice])->render();
        App::setLocale('en');

        $this->assertStringContainsString('Aucune ligne sur la commande source.', $html);
    }

    private function invoiceWithOneLine(): Invoice
    {
        $invoiceId = TenantContext::run($this->tenant, function (): string {
            $order = $this->confirmedOrder($this->account, $this->owner, 120);
            $invoice = $this->sentInvoice($order, 120);

            $product = (new ProductFactory)->create();
            $variant = (new VariantFactory)->create(['product_id' => $product->id]);

            $orderLine = new OrderLine([
                'order_id' => $order->getKey(),
                'variant_id' => $variant->getKey(),
                'description' => 'Test line',
                'quantity' => 4,
                'unit_price' => 30,
                'discount' => 0,
                'line_total' => 120,
            ]);
            $orderLine->save();

            return $invoice->getKey();
        });

        return TenantContext::run(
            $this->tenant,
            fn () => Invoice::query()
                ->with(['order.account', 'order.contact', 'order.orderLines.variant.product', 'tenant'])
                ->find($invoiceId)
        );
    }
}
