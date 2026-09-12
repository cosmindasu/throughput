<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * FR-DEMO-01 + FR-TEN-01, verificate prin cerere HTTP reală — adică prin tot lanțul care
 * contează: `auth → SetSessionContext → ResolveWorkspace`, global scope, RLS, roluri per
 * tenant, props Inertia.
 *
 * Deliberat NU folosește factories: la momentul scrierii, seed-ul de volum era construit în
 * paralel, iar un test de shell care depinde de datele de demo ar fi început să pice din
 * motive care n-au nimic de-a face cu shell-ul.
 */
class DashboardTest extends TestCase
{
    private Tenant $marlin;

    private Tenant $cascade;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');

        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->makeMember($this->cascade, 'demo.owner@throughput.dev', Permissions::OWNER, user: $this->owner);

        $this->seedBusinessData($this->marlin, dealValue: 12_500.75, overdueBalance: 3_410.20);
        $this->seedBusinessData($this->cascade, dealValue: 999_999.99, overdueBalance: 888_888.88);

        $this->clearDatabaseTenantContext();
    }

    public function test_the_dashboard_shows_kpis_computed_from_the_current_workspace_only(): void
    {
        $response = $this->actingAs($this->owner)->get('/marlin/dashboard');

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('kpis.openPipelineValue', 12500.75)
            ->where('kpis.ordersThisMonth', 1)
            ->where('kpis.overdueInvoices.count', 1)
            ->where('kpis.overdueInvoices.amount', 3410.2)
            ->has('activity')
            ->where('workspace.name', 'Marlin Fasteners & Supply Co.')
        );

        // Aceleași KPI-uri, alt workspace, aceeași sesiune: dacă vreo cifră ar fi
        // moștenită, s-ar vedea aici. Cifrele celui de-al doilea tenant sunt deliberat
        // absurd de mari, ca o scurgere să fie imposibil de confundat cu o coincidență.
        $this->actingAs($this->owner)->get('/cascade/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kpis.openPipelineValue', 999999.99)
                ->where('kpis.overdueInvoices.amount', 888888.88)
                ->where('workspace.name', 'Cascade Hydraulic Components')
            );
    }

    public function test_the_switcher_lists_every_workspace_the_user_belongs_to(): void
    {
        $this->actingAs($this->owner)->get('/marlin/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('workspaces', 2)
                ->where('workspaces.0.slug', 'cascade')   // ordonate după numele tenantului
                ->where('workspaces.1.slug', 'marlin')
            );
    }

    public function test_a_user_cannot_open_a_workspace_they_are_not_a_member_of(): void
    {
        $stranger = $this->makeMember($this->cascade, 'stranger@throughput.dev', Permissions::VIEWER);

        $this->clearDatabaseTenantContext();

        // 404, nu 403: existența workspace-ului nu se confirmă cuiva din afara lui.
        $this->actingAs($stranger)->get('/marlin/dashboard')->assertNotFound();
    }

    public function test_the_navigation_permissions_differ_between_roles(): void
    {
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);

        $this->clearDatabaseTenantContext();

        // Cheile lui `navigation` CONȚIN puncte („billing.view"), deci nu pot fi adresate
        // cu notația cu punct a aserțiunilor Inertia — ea ar căuta `navigation → billing → view`.
        $this->assertNavigationPermissions($this->owner, ['billing.view' => true, 'members.view' => true]);

        // Criteriul de acceptanță din §7.3: Managerul NU vede „Billing & Subscription".
        $this->assertNavigationPermissions($manager, ['billing.view' => false, 'members.view' => true]);

        $this->assertNavigationPermissions($viewer, [
            'billing.view' => false,
            'members.view' => false,
            'accounts.view' => true,
        ]);
    }

    /**
     * @param  array<string, bool>  $expected
     */
    private function assertNavigationPermissions(User $user, array $expected): void
    {
        $response = $this->actingAs($user)->get('/marlin/dashboard');
        $this->assertSame(200, $response->getStatusCode(), 'Status pentru '.$user->email.': '.$response->getStatusCode().' '.substr(strip_tags($response->getContent()), 0, 300));
        $response
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation', function ($navigation) use ($expected): bool {
                    foreach ($expected as $permission => $allowed) {
                        if (($navigation[$permission] ?? null) !== $allowed) {
                            return false;
                        }
                    }

                    return true;
                })
            );
    }

    private function seedBusinessData(Tenant $tenant, float $dealValue, float $overdueBalance): void
    {
        TenantContext::run($tenant, function () use ($dealValue, $overdueBalance): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();

            $pipeline = Pipeline::query()->create(['name' => 'Standard']);
            $stage = Stage::query()->create([
                'pipeline_id' => $pipeline->getKey(),
                'name' => 'Qualification',
                'position' => 1,
            ]);

            $deal = new Deal([
                'account_id' => $account->getKey(),
                'pipeline_id' => $pipeline->getKey(),
                'stage_id' => $stage->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'title' => 'Annual fastener supply agreement',
                'value' => $dealValue,
                'status' => Deal::STATUS_OPEN,
            ]);
            $deal->created_by = $this->owner->getKey();
            $deal->save();

            $order = new Order([
                'account_id' => $account->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => 'confirmed',
                'grand_total' => 4_217.44,
            ]);
            $order->created_by = $this->owner->getKey();
            $order->save();

            // `order_id` nu e fillable pe Invoice (spre deosebire de `account_id` pe Order):
            // factura se emite dintr-o comandă, prin serviciu, nu din input de utilizator.
            $invoice = new Invoice([
                'status' => Invoice::STATUS_OVERDUE,
                'total' => $overdueBalance,
                'balance_due' => $overdueBalance,
                'due_date' => now()->subDays(14),
            ]);
            $invoice->order_id = $order->getKey();
            $invoice->save();
        });
    }
}
