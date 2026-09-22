<?php

namespace Tests\Feature\Invoices;

use App\Actions\Invoices\RegisterPaymentAction;
use App\Models\Account;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesInvoices;
use Tests\TestCase;

/**
 * specs.md §15.8 FR-I18N-03 — sumele interpolate într-un mesaj de refuz urmează moneda
 * facturii ȘI limba cererii.
 *
 * Ramura testată aici e apărarea în adâncime din `RegisterPaymentAction`: `StorePaymentRequest`
 * validează deja `amount <= balance_due`, dar balanța poate scădea între validare și blocarea
 * rândului. Nu e atinsă prin HTTP fără o cursă reală, deci acțiunea se apelează direct — altfel
 * ramura ar rămâne, ca până acum, complet neacoperită.
 *
 * Ce apăra ea greșit înainte de Valul 5: `'$'.$amount`, cu un comentariu care amâna formatarea
 * „la Valul 3 (frontend)" — val care nu putea prelua niciodată un mesaj de backend. Defectul NU
 * era doar de limbă: `tenants.currency` e configurabilă (specs.md §2.3), deci un tenant pe EUR
 * citea „$1234.5" **și în engleză**. De-asta testul verifică amândouă axele, nu doar franceza.
 */
class PaymentRefusalFormattingTest extends TestCase
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

    public function test_the_refusal_formats_amounts_in_french_when_the_locale_is_french(): void
    {
        App::setLocale('fr');

        $message = $this->refusalFor(currency: 'USD', balance: 1234.56, attempted: 2000.0);

        // U+202F la mii, U+00A0 înaintea simbolului, simbolul DUPĂ sumă — și cel „îngust".
        $this->assertStringContainsString("1\u{202F}234,56\u{00A0}$", $message);
        $this->assertStringNotContainsString('$US', $message);
        $this->assertStringNotContainsString('$1234.56', $message);
    }

    public function test_the_refusal_uses_the_invoice_currency_not_a_hardcoded_dollar_sign(): void
    {
        // Axa care era greșită INDEPENDENT de limbă: un tenant pe EUR citea un semn de dolar.
        App::setLocale('en');

        $message = $this->refusalFor(currency: 'EUR', balance: 1234.56, attempted: 2000.0);

        $this->assertStringContainsString("\u{20AC}1,234.56", $message);
        $this->assertStringNotContainsString('$', $message);
    }

    public function test_english_keeps_its_own_convention(): void
    {
        App::setLocale('en');

        $message = $this->refusalFor(currency: 'USD', balance: 1234.56, attempted: 2000.0);

        $this->assertStringContainsString('$1,234.56', $message);
        $this->assertStringContainsString('$2,000.00', $message);
    }

    /** Declanșează ramura de refuz și întoarce mesajul ei, pe câmpul `amount`. */
    private function refusalFor(string $currency, float $balance, float $attempted): string
    {
        return TenantContext::run($this->tenant, function () use ($currency, $balance, $attempted): string {
            $order = $this->confirmedOrder($this->account, $this->owner, $balance, $currency);
            $invoice = $this->sentInvoice($order, $balance);

            try {
                (new RegisterPaymentAction)->execute($invoice, [
                    'amount' => $attempted,
                    'method' => 'manual',
                    'paid_at' => now(),
                ], $this->owner);
            } catch (ValidationException $e) {
                return $e->errors()['amount'][0];
            }

            $this->fail('Plata peste balanță ar fi trebuit respinsă.');
        });
    }
}
