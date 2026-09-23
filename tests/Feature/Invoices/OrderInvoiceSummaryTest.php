<?php

namespace Tests\Feature\Invoices;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\Concerns\CreatesInvoices;
use Tests\TestCase;

/**
 * TEST-01 (audit 2026-09-23, `docs/reviews/2026-09-23_audit/11-teste.md`) —
 * `InvoiceController::forOrder()` (rută `orders.invoice.summary`, consumată de
 * `BillingSection` pe `Orders/Show.tsx`) reintroducea EXACT bugul deja reparat o dată în
 * `Order::invoice()` (`.ai/rules/tenancy.md`, „`created_at` are precizie 0 — nu ordonează
 * singur nimic"): interoga manual `Invoice::query()->latest('created_at')->first()`, fără
 * niciun tiebreaker pe `id`. Rută complet netestată înainte de acest fișier — `grep -rn
 * "forOrder\|invoice-summary" tests/ e2e/` nu găsea nimic în afară de un comentariu
 * explicativ în `e2e/specs/order-fulfilment.spec.ts:154`, care documentează de ce testul E2E
 * NU așteaptă acest endpoint, nu o verificare a lui.
 */
class OrderInvoiceSummaryTest extends TestCase
{
    use CreatesInvoices;

    private Tenant $marlin;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($this->marlin, 'setup.owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function () use ($owner): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC', 'credit_terms' => 'net_30']);
            $account->created_by = $owner->getKey();
            $account->save();
            $this->account = $account;
        });

        $this->clearDatabaseTenantContext();
    }

    /**
     * Reproduce EXACT scenariul din memoria proiectului (o anulare urmată de o reemitere,
     * în aceeași secundă): `created_at` IDENTIC pe ambele facturi, forțat printr-un UPDATE
     * — proba nu depinde de cât de repede rulează testul, la fel ca
     * `CreateInvoiceActionTest::test_order_invoice_relation_breaks_a_created_at_tie_by_id()`.
     * Fără tiebreaker pe `id`, Postgres nu garantează nicio ordine între cele două rânduri
     * la egalitate de `created_at` — inclusiv întoarcerea facturii ANULATE pe acest
     * endpoint, exact bugul TEST-01.
     */
    public function test_returns_the_newest_invoice_when_a_voided_and_its_replacement_share_the_same_second(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner1@throughput.dev', Permissions::OWNER);
        $order = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 500));

        [$voidedId, $replacementId] = TenantContext::run($this->marlin, function () use ($order) {
            $voided = $this->sentInvoice($order, 500);
            $voided->update(['status' => Invoice::STATUS_VOID, 'void_reason' => 'Wrong amount.', 'voided_at' => now()]);

            $replacement = $this->sentInvoice($order, 500);

            // `created_at` identic, forțat — la fel ca în `CreateInvoiceActionTest`, nu
            // sperat din viteza testului.
            $tiedAt = now();
            Invoice::query()->whereIn('id', [$voided->getKey(), $replacement->getKey()])->update(['created_at' => $tiedAt]);

            return [$voided->getKey(), $replacement->getKey()];
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($owner)
            ->getJson("/marlin/orders/{$order->getKey()}/invoice-summary")
            ->assertOk();

        $response->assertJsonPath('invoice.id', $replacementId);
        $this->assertNotSame($voidedId, $response->json('invoice.id'));
    }

    public function test_returns_a_null_invoice_and_can_create_true_when_the_order_has_none_yet(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner2@throughput.dev', Permissions::OWNER);
        $order = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 500));
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->getJson("/marlin/orders/{$order->getKey()}/invoice-summary")
            ->assertOk()
            ->assertJsonPath('invoice', null)
            ->assertJsonPath('can.create', true);
    }

    /**
     * `OrderPolicy::view()` (singura autorizare din `forOrder()`) verifică DOAR
     * `orders.view` — permisiune pe care toate cele patru roluri o au
     * (`Permissions::forRoles()`), spre deosebire de alte acțiuni pe comenzi (editare,
     * anulare), care se îngustează la înregistrările proprii ale unui Agent. Verificat
     * direct în cod (nu presupus): un test care ar fi afirmat „Viewer → 403" pe RUTA asta
     * ar fi verificat un comportament care nu există — `test_an_agent_sees_a_foreign_order_
     * but_without_write_rights()` din `OrderCrudHttpTest` confirmă același lucru pentru
     * `orders.show`. Diferențierea reală pe rol e în `can.create`, verificată separat mai
     * jos (`invoices.create` există doar la Owner/Manager).
     */
    public function test_all_four_roles_can_read_the_summary_of_a_colleagues_order(): void
    {
        $owner = $this->makeMember($this->marlin, 'roleowner@throughput.dev', Permissions::OWNER);
        $manager = $this->makeMember($this->marlin, 'rolemanager@throughput.dev', Permissions::MANAGER);
        $agent = $this->makeMember($this->marlin, 'roleagent@throughput.dev', Permissions::AGENT);
        $viewer = $this->makeMember($this->marlin, 'roleviewer@throughput.dev', Permissions::VIEWER);
        $order = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 500));
        $this->clearDatabaseTenantContext();

        foreach ([$owner, $manager, $agent, $viewer] as $user) {
            $this->actingAs($user)
                ->getJson("/marlin/orders/{$order->getKey()}/invoice-summary")
                ->assertOk();
        }
    }

    /** `invoices.create` există doar la Owner/Manager (§7.4) — Agent și Viewer n-o au. */
    public function test_can_create_reflects_the_invoices_create_permission_per_role(): void
    {
        $owner = $this->makeMember($this->marlin, 'permowner@throughput.dev', Permissions::OWNER);
        $viewer = $this->makeMember($this->marlin, 'permviewer@throughput.dev', Permissions::VIEWER);
        $order = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 500));
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->getJson("/marlin/orders/{$order->getKey()}/invoice-summary")
            ->assertOk()
            ->assertJsonPath('can.create', true);

        $this->actingAs($viewer)
            ->getJson("/marlin/orders/{$order->getKey()}/invoice-summary")
            ->assertOk()
            ->assertJsonPath('can.create', false);
    }

    /** BOLA (OWASP API1:2023) — comanda altui tenant nu e adresabilă, nici prin acest fetch propriu. */
    public function test_an_order_from_another_tenant_is_not_found(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $stranger = $this->makeMember($cascade, 'stranger@throughput.dev', Permissions::OWNER);

        $owner = $this->makeMember($this->marlin, 'crossowner@throughput.dev', Permissions::OWNER);
        $order = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 500));
        $this->clearDatabaseTenantContext();

        $this->actingAs($stranger)->getJson("/cascade/orders/{$order->getKey()}/invoice-summary")->assertNotFound();
    }
}
