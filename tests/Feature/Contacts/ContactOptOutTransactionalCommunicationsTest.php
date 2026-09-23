<?php

namespace Tests\Feature\Contacts;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Order;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * BR-CRM-02 (specs.md): „`opt_out = true` pe un contact suprimă doar comunicările de
 * marketing viitoare (dacă ar exista, Faza 2) — nu afectează comunicările tranzacționale
 * (confirmări de comandă, facturi)."
 *
 * **Ce NU acoperă acest fișier, și de ce.** Jumătatea „suprimă marketingul" a regulii e
 * netestabilă azi: nu există niciun expeditor de marketing în cod (Faza 2 nu s-a scris
 * încă), deci n-are ce să suprime un test. Ce e mai mult — verificat prin grep exhaustiv
 * pe `app/` la 2026-09-22 (vezi și `ContactOptOutFilterGuardTest`) — nici jumătatea
 * „comunicări tranzacționale" nu are un expeditor LITERAL de verificat: nu există
 * `OrderConfirmationMail`, nu există `InvoiceMail`, nu există niciun `Mail::to()`/
 * `Notification::send()` care țintească adresa unui `Contact`. Deci acest test NU poate
 * afirma „un contact opt-out a primit un email de confirmare" — n-ar avea ce email să
 * verifice.
 *
 * Ce E testabil și regresabil azi: contactul rămâne pe deplin ELIGIBIL într-un flux de
 * business tranzacțional — crearea unei comenzi (`Order`) care-l referă ca `contact_id`.
 * `StoreOrderRequest::rules()` filtrează `contact_id` doar după `anonymized_at IS NULL`
 * (§20.5, contact șters/anonimizat) — NICIODATĂ după `opt_out`. Dacă cineva ar adăuga
 * într-o zi o astfel de excludere („nu las un contact opt-out să fie pus pe o comandă"),
 * ar încălca exact BR-CRM-02, iar testul de mai jos ar deveni roșu — asta demonstrează
 * regresia pe care o prinde, nu presupune că ar prinde-o.
 */
class ContactOptOutTransactionalCommunicationsTest extends TestCase
{
    public function test_a_contact_with_marketing_opt_out_remains_fully_usable_in_a_transactional_order(): void
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $account = TenantContext::run($marlin, function () use ($owner): Account {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $owner->getKey();
            $account->save();

            return $account;
        });

        $optedOutContact = TenantContext::run($marlin, function () use ($account, $owner): Contact {
            $contact = new Contact([
                'account_id' => $account->getKey(),
                'first_name' => 'Jamie',
                'last_name' => 'Smith',
                'email' => 'jamie.smith@northwind.test',
                'opt_out' => true,
            ]);
            $contact->created_by = $owner->getKey();
            $contact->save();

            return $contact;
        });

        $this->assertTrue($optedOutContact->opt_out, 'Sanity check on the fixture itself.');
        $this->clearDatabaseTenantContext();

        // Fluxul tranzacțional real: crearea unei comenzi (draft, US-ORD-01) pentru
        // exact acest contact — precursorul oricărei confirmări/facturi viitoare.
        $response = $this->actingAs($owner)->post('/marlin/orders', [
            'account_id' => $account->getKey(),
            'contact_id' => $optedOutContact->getKey(),
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $order = TenantContext::run(
            $marlin,
            fn () => Order::query()->where('account_id', $account->getKey())->firstOrFail()
        );

        $this->assertSame(
            $optedOutContact->getKey(),
            $order->contact_id,
            'The order must keep the opted-out contact as its contact — opt_out is not a transactional gate (BR-CRM-02).'
        );
        $this->clearDatabaseTenantContext();

        // Al doilea unghi al aceleiași afirmații: comanda se deschide normal, cu
        // contactul opt-out ATAȘAT și vizibil — nu ascuns, nu redactat, nu blocat —
        // exact ca orice alt contact, pe o pagină pe care s-ar afișa în viitor o
        // confirmare/factură reală.
        $this->actingAs($owner)
            ->get("/marlin/orders/{$order->getKey()}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->where('order.contact.id', $optedOutContact->getKey())
            );
    }
}
