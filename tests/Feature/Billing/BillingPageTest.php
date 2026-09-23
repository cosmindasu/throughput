<?php

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

/**
 * FR-BILL-01 — „Pagină «Billing & Subscription» (vizibilă doar Owner, §7.4): plan curent,
 * metodă de plată, istoric... buton către Stripe Customer Portal." §7.3 criteriul de
 * acceptanță: Managerul nu vede opțiunea ÎN Settings ȘI nu poate deschide URL-ul direct.
 */
class BillingPageTest extends TestCase
{
    private Tenant $tenant;

    private User $owner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->tenant, 'manager@throughput.dev', Permissions::MANAGER);

        TenantContext::run($this->tenant, function (): void {
            Subscription::query()->create([
                'user_id' => $this->tenant->getKey(),
                'type' => 'default',
                'stripe_id' => 'sub_marlin_test',
                'stripe_status' => 'active',
                'stripe_price' => 'price_pro_monthly',
            ]);
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_the_owner_sees_the_current_plan_and_can_manage(): void
    {
        $this->actingAs($this->owner)
            ->get('/marlin/settings/billing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Billing/Index')
                ->where('subscription.status', 'active')
                ->where('subscription.plan', 'price_pro_monthly')
                ->where('subscription.accessLevel', 'full')
                ->where('can.manage', true)
                // §22.4 — niciun apel real către Stripe în teste: acest tenant n-are
                // `stripe_id`, deci `Billable::invoices()` se oprește ÎNAINTE de orice
                // cerere de rețea (`ManagesInvoices::invoices()`, verificat în vendor) —
                // `[]`, nu o eroare. Calea CU facturi reale ar cere un dublu Stripe HTTP
                // client fals, nesemnalat ca lucru de făcut (raportul lotului).
                ->where('invoices', [])
            );
    }

    public function test_the_manager_is_forbidden_even_with_a_direct_url(): void
    {
        // §7.1/§7.3 — „acces operațional complet, FĂRĂ billing": nu doar cardul ascuns din
        // Settings, un 403 real dacă Managerul forțează URL-ul.
        $this->actingAs($this->manager)
            ->get('/marlin/settings/billing')
            ->assertForbidden();
    }

    public function test_the_manager_cannot_open_the_portal_either(): void
    {
        $this->actingAs($this->manager)
            ->post('/marlin/settings/billing/portal')
            ->assertForbidden();
    }

    /**
     * ADR-023 — asimetria închisă: `portal()` n-avea `try/catch`, spre deosebire de
     * `invoiceHistory()`. Tenantul de test n-are `stripe_id` (§22.4, ca la
     * `test_the_owner_sees_the_current_plan_and_can_manage()` de mai sus), deci
     * `billingPortalUrl()` aruncă `Laravel\Cashier\Exceptions\InvalidCustomer`
     * (`assertCustomerExists()`) ÎNAINTE de orice cerere de rețea — exact mecanismul deja
     * folosit în acest fișier pentru a evita un Stripe fals, nu unul nou.
     */
    public function test_portal_failure_redirects_back_with_a_translated_flash_error_instead_of_500(): void
    {
        $this->actingAs($this->owner)
            ->from('/marlin/settings/billing')
            ->post('/marlin/settings/billing/portal')
            ->assertRedirect('/marlin/settings/billing')
            ->assertSessionHas('error', __('flash.subscription.portal_unavailable'));
    }
}
