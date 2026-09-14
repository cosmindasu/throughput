<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Contacts\ContactErasure;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * §9 task punctul 6 / specs.md §20.5 — `Order::contact()` se încarcă în `show()` ȘI
 * `edit()` cu bypass-ul `NotAnonymizedContactScope`, exact ca `DealController`: o
 * comandă cu contact anonimizat se deschide și se editează fără să piardă legătura.
 */
class OrderContactAnonymizationTest extends TestCase
{
    private Tenant $marlin;

    private Account $account;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function (): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_an_order_with_an_anonymized_contact_still_shows_and_edits_with_the_link_intact(): void
    {
        $order = $this->orderWithAnonymizedContact();
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->get("/marlin/orders/{$order->getKey()}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->where('order.contact.isAnonymized', true)
                ->has('order.contact.id')
            );

        $this->actingAs($this->owner)
            ->get("/marlin/orders/{$order->getKey()}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Edit')
                ->where('order.contact.isAnonymized', true)
                ->has('order.contact.id')
            );
    }

    /**
     * Editarea unei comenzi cu contact anonimizat, fără atinge acel câmp, nu trebuie
     * să-l golească tăcut (aceeași capcană documentată pentru `UpdateDealRequest`).
     */
    public function test_saving_the_form_without_changing_the_contact_keeps_the_link(): void
    {
        $order = $this->orderWithAnonymizedContact();
        $contactId = $order->contact_id;
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->put("/marlin/orders/{$order->getKey()}", [
                'account_id' => $this->account->getKey(),
                'contact_id' => $contactId,
                'notes' => 'Updated notes',
                'lines' => [],
            ])
            ->assertRedirect("/marlin/orders/{$order->getKey()}");

        TenantContext::run($this->marlin, function () use ($order, $contactId): void {
            $this->assertSame($contactId, $order->fresh()->contact_id);
        });
    }

    private function orderWithAnonymizedContact(): Order
    {
        return TenantContext::run($this->marlin, function (): Order {
            $contact = new Contact([
                'account_id' => $this->account->getKey(),
                'first_name' => 'Jamie',
                'last_name' => 'Smith',
            ]);
            $contact->created_by = $this->owner->getKey();
            $contact->save();

            $order = new Order([
                'account_id' => $this->account->getKey(),
                'contact_id' => $contact->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => OrderStatus::Draft,
                'currency' => 'USD',
            ]);
            $order->created_by = $this->owner->getKey();
            $order->save();

            // Contactul are cel puțin această comandă drept referință — ContactErasure
            // anonimizează în loc să șteargă fizic (§20.5).
            ContactErasure::erase($contact->getKey());

            return $order->fresh();
        });
    }
}
