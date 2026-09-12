<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FR-TEST-02 — comutarea contextului în mijlocul suitei.
 *
 * Testele de izolare „curate" (un tenant per test) ratează clasa de bug care contează
 * cel mai mult în practică: contextul care se lipește de conexiune. Aici contextul se
 * schimbă de zeci de ori pe aceeași conexiune, în ordine amestecată, iar fiecare pas
 * verifică AMBELE straturi — Eloquent și SQL brut, care ocolește deliberat Eloquent.
 */
class TenantContextFuzzTest extends TestCase
{
    public function test_switching_tenants_repeatedly_never_leaks_rows(): void
    {
        $user = null;
        $tenants = [];

        foreach (['marlin' => 4, 'cascade' => 2, 'northgate' => 3] as $slug => $accountCount) {
            $tenant = $this->makeTenant($slug);
            $user ??= $this->makeMember($tenant, 'demo.owner@throughput.dev');

            TenantContext::run($tenant, function () use ($accountCount, $user, $slug): void {
                for ($i = 1; $i <= $accountCount; $i++) {
                    $account = new Account(['name' => ucfirst($slug)." Supply {$i}"]);
                    $account->created_by = $user->getKey();
                    $account->save();
                }
            });

            $tenants[$slug] = ['tenant' => $tenant, 'expected' => $accountCount];
        }

        // Ordine amestecată, dar deterministă (fără `rand()`): un test care eșuează o dată
        // la zece rulări nu e un test, e un zvon.
        $sequence = ['marlin', 'cascade', 'marlin', 'northgate', 'cascade', 'northgate', 'marlin', 'cascade'];

        foreach ($sequence as $slug) {
            ['tenant' => $tenant, 'expected' => $expected] = $tenants[$slug];

            TenantContext::run($tenant, function () use ($tenant, $expected): void {
                $this->assertSame($expected, Account::query()->count());

                // Stratul 2, verificat independent: dacă global scope-ul ar fi singura
                // plasă, linia de mai jos ar întoarce toate cele 9 conturi.
                $raw = DB::table('accounts')->pluck('tenant_id')->unique();
                $this->assertSame([$tenant->getKey()], $raw->values()->all());

                // Și agregările, nu doar listele — un `SUM` care scapă e la fel de grav
                // ca o listă care scapă, dar mult mai greu de observat pe ecran.
                $this->assertSame($expected, (int) DB::table('accounts')->count());
            });

            // Între două contexte, procesul rămâne fără tenant legat în container.
            $this->assertNull(app()->bound(TenantScope::CONTAINER_KEY)
                ? app(TenantScope::CONTAINER_KEY)
                : null);
        }
    }

    public function test_a_context_set_for_a_tenant_that_does_not_exist_shows_nothing(): void
    {
        $marlin = $this->makeTenant('marlin');
        $user = $this->makeMember($marlin, 'demo.owner@throughput.dev');

        TenantContext::run($marlin, function () use ($user): void {
            $account = new Account(['name' => 'Marlin Industrial Fasteners LLC']);
            $account->created_by = $user->getKey();
            $account->save();
        });

        // Un ULID valid ca formă, dar inexistent — cazul „am pus tenantul greșit în job".
        TenantContext::run((string) str()->ulid(), function (): void {
            $this->assertSame(0, Account::query()->count());
            $this->assertSame(0, DB::table('accounts')->count());
        });
    }

    public function test_an_exception_inside_a_context_does_not_leave_the_context_behind(): void
    {
        $marlin = $this->makeTenant('marlin');

        try {
            TenantContext::run($marlin, function (): void {
                throw new \RuntimeException('eșec la mijlocul unei operații în masă');
            });
        } catch (\RuntimeException) {
            // Așteptat.
        }

        $this->assertNull(app()->bound(TenantScope::CONTAINER_KEY)
            ? app(TenantScope::CONTAINER_KEY)
            : null);
    }
}
