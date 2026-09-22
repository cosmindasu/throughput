<?php

namespace Tests\Feature\Members;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Members\DeactivatedMemberIds;
use App\Support\Members\DeactivatedMemberNames;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * FR-TEN-04 — placeholder „(deactivated)" pe owner/actor, calculat server-side, o singură
 * dată per cerere (App\Support\Members\DeactivatedMemberNames), fără N+1: verificat pe
 * `Accounts/Index` (Pachetul D construiește pagina, dar `AccountResource` e al meu de
 * modificat aici — raportul pachetului) și pe feed-ul de activitate al dashboard-ului.
 */
class DeactivatedMemberPlaceholderTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $jane;

    protected function setUp(): void
    {
        parent::setUp();

        config(['throughput.demo.mode' => false]);

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);

        $this->actingAs($this->owner)->post(
            "/marlin/settings/members/{$this->membershipOf($this->jane)->getKey()}/deactivate",
            ['reassign' => false],
        );
    }

    public function test_a_deactivated_owner_shows_the_placeholder_on_the_accounts_list(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $account = new Account(['name' => 'Janes Account', 'owner_user_id' => $this->jane->getKey()]);
            $account->created_by = $this->owner->getKey();
            $account->save();
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->get('/marlin/accounts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Index')
                // `accounts` e un prop deferred (FR-PERF-01) — cerut explicit cu
                // `loadDeferredProps()`, ca la `AccountIndexTest`.
                ->loadDeferredProps(fn (Assert $deferred) => $deferred->where('accounts.data.0.owner.name', 'Jane (deactivated)'))
            );
    }

    /**
     * FR-I18N-04, Lotul I18N Val 5 — până acum `DeactivatedMemberNames::label()` concatena
     * „(deactivated)" literal, în engleză, INDIFERENT de `users.locale` al celui care
     * privește lista. Aserțiune NOUĂ, alături de cea engleză de mai sus (neschimbată — proba
     * că literalul englezesc a rămas identic bit cu bit pentru un utilizator care n-a atins
     * comutatorul de limbă). Locale-ul care contează e al VIEWER-ULUI (`$this->owner`, cel
     * care cere pagina), nu al lui Jane (membrul dezactivat) — la fel ca în
     * `MemberRefusalLocaleTest`/`ValidationMessageLocaleTest`.
     */
    public function test_a_deactivated_owner_shows_the_french_placeholder_on_the_accounts_list(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $account = new Account(['name' => 'Janes Account', 'owner_user_id' => $this->jane->getKey()]);
            $account->created_by = $this->owner->getKey();
            $account->save();
        });
        $this->owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->get('/marlin/accounts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Index')
                ->loadDeferredProps(fn (Assert $deferred) => $deferred->where('accounts.data.0.owner.name', 'Jane (désactivé)'))
            );
    }

    /**
     * Interogarea care încarcă setul de user_id dezactivați rulează O SINGURĂ DATĂ per
     * cerere (`App\Support\Members\DeactivatedMemberIds`, `scoped()`), indiferent de câte
     * rânduri au owner dezactivat pe pagină — la fel ca testul de N+1 al Pachetului
     * „Orders" (`OrderCrudHttpTest`), cu ACELAȘI request de reload parțial: `accounts` e
     * un prop deferred (FR-PERF-01), deci un `GET` simplu, fără header-ele de mai jos,
     * nu evaluează deloc closure-ul — testul ar trece „verde" fără să verifice nimic.
     */
    public function test_the_placeholder_lookup_does_not_grow_with_the_number_of_rows(): void
    {
        $version = $this->actingAs($this->owner)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => 'warmup',
                'X-Inertia-Partial-Component' => 'Accounts/Index',
                'X-Inertia-Partial-Data' => 'accounts',
            ])
            ->get('/marlin/accounts')
            ->headers->get('x-inertia-version');

        $queryCountFor = function (int $accountCount) use ($version): int {
            TenantContext::run($this->marlin, function () use ($accountCount): void {
                Account::query()->delete();

                for ($i = 0; $i < $accountCount; $i++) {
                    $account = new Account(['name' => "Janes Account {$i}", 'owner_user_id' => $this->jane->getKey()]);
                    $account->created_by = $this->owner->getKey();
                    $account->save();
                }
            });
            $this->clearDatabaseTenantContext();

            // Ca în `OrderCrudHttpTest` — instanțele `scoped()` supraviețuiesc între
            // cereri ÎN ACEST PROCES de test, spre deosebire de producție (proces PHP-FPM
            // nou per cerere) sau de un job de coadă (Laravel le aruncă înainte de
            // fiecare). Fără linia asta, a doua măsurătoare ar găsi cache-ul deja cald.
            $this->app->forgetScopedInstances();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)
                ->withHeaders([
                    'X-Inertia' => 'true',
                    'X-Inertia-Version' => $version,
                    'X-Inertia-Partial-Component' => 'Accounts/Index',
                    'X-Inertia-Partial-Data' => 'accounts',
                ])
                ->get('/marlin/accounts')
                ->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $withOne = $queryCountFor(1);
        $withTen = $queryCountFor(10);

        $this->assertSame($withOne, $withTen, 'Setul de membri dezactivați trebuie încărcat o singură dată per cerere, nu per rând.');
    }

    public function test_the_dashboard_activity_feed_marks_a_deactivated_actor(): void
    {
        TenantContext::run($this->marlin, function (): void {
            ActivityLog::query()->create([
                'user_id' => $this->jane->getKey(),
                'action' => 'created',
                'auditable_type' => Account::class,
                'auditable_id' => (string) Str::ulid(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
            ]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->get('/marlin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('activity.0.actor', 'Jane (deactivated)')
            );
    }

    /**
     * Regresie (găsită de coordonator, nu la review) — varianta inițială a
     * `DeactivatedMemberNames` lega un binding de container cu o închidere care
     * CAPTURA setul primului tenant rezolvat; pe un worker Horizon care procesează
     * joburi pentru tenanți diferiți succesiv, al doilea tenant moștenea tăcut setul
     * primului. `App\Support\Members\DeactivatedMemberIds` cheie cache-ul PE tenant, în
     * proprietatea instanței — acest test simulează exact acel scenariu: două „joburi"
     * consecutive, pe tenanți diferiți, în ACELAȘI proces, cu ACELAȘI utilizator global,
     * dezactivat doar în primul tenant.
     */
    public function test_the_placeholder_does_not_leak_across_tenants_on_a_reused_process(): void
    {
        $acme = $this->makeTenant('acme', 'Acme Tooling Co.');
        $globex = $this->makeTenant('globex', 'Globex Industrial');

        $jane = User::query()->create([
            'name' => 'Jane',
            'email' => 'jane.cross-tenant@throughput.dev',
            'password' => 'password',
        ]);

        $this->makeMember($acme, $jane->email, Permissions::AGENT, $jane);
        $this->makeMember($globex, $jane->email, Permissions::AGENT, $jane);

        // „Job" 1, tenantul A — dezactivare ȘI etichetare în ACEEAȘI execuție: arată
        // „(deactivated)" imediat, fără o a doua cerere/job.
        TenantContext::run($acme, function () use ($jane, $acme): void {
            app()->instance('tenant', $acme);

            Membership::query()->where('user_id', $jane->getKey())->update([
                'status' => Membership::STATUS_DEACTIVATED,
                'deactivated_at' => now(),
            ]);
            app(DeactivatedMemberIds::class)->forgetCurrentTenant();

            $this->assertTrue(DeactivatedMemberNames::isDeactivated($jane->getKey()));
            $this->assertSame('Jane (deactivated)', DeactivatedMemberNames::label('Jane', $jane->getKey()));
        });

        // Worker-ul de coadă aruncă instanțele `scoped()` ÎNTRE joburi
        // (`QueueServiceProvider`) — reprodus explicit aici, nu presupus.
        app()->forgetScopedInstances();

        // „Job" 2, tenantul B — Jane e membru ACTIV aici; eticheta NU trebuie să poarte
        // starea tenantului A.
        TenantContext::run($globex, function () use ($jane, $globex): void {
            app()->instance('tenant', $globex);

            $this->assertFalse(DeactivatedMemberNames::isDeactivated($jane->getKey()));
            $this->assertSame('Jane', DeactivatedMemberNames::label('Jane', $jane->getKey()));
        });
    }

    private function membershipOf(User $user): Membership
    {
        return TenantContext::run($this->marlin, fn () => Membership::query()->where('user_id', $user->getKey())->firstOrFail());
    }
}
