<?php

namespace Tests\Feature\Demo;

use App\Models\Account;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Tests\TestCase;

/**
 * `demo:seed-volume --scale` — setul redus pentru suita E2E (plan §5.3, minutele de CI).
 *
 * E singurul test care rulează generatorul de seed cap-coadă. La scară completă durează
 * ~60 s; la scara minimă, câteva secunde. Prinde regresiile de generare (ordinea cheilor
 * străine, contextul de tenant, forma datelor) înainte să ajungă la `demo:reset` în producție.
 */
class DemoSeedScaleTest extends TestCase
{
    public function test_the_scaled_dataset_keeps_every_shape_the_e2e_flows_need(): void
    {
        $this->artisan('demo:seed-volume', ['--scale' => '0.001'])->assertSuccessful();

        $this->assertSame(['cascade', 'marlin', 'northgate'], Tenant::query()->orderBy('slug')->pluck('slug')->all());

        foreach (['owner', 'manager', 'agent', 'viewer'] as $role) {
            $this->assertTrue(
                User::query()->where('email', "demo.{$role}@throughput.dev")->exists(),
                "Lipsește contul demo {$role} (specs.md §4.2)."
            );
        }

        $marlin = Tenant::query()->where('slug', 'marlin')->firstOrFail();
        $agent = User::query()->where('email', 'demo.agent@throughput.dev')->firstOrFail();

        TenantContext::run($marlin, function () use ($agent): void {
            // Minimele scalării, nu 0,1% din volumul complet.
            $this->assertSame(40, Account::query()->count());
            $this->assertSame(60, Order::query()->count());
            $this->assertGreaterThan(0, Deal::query()->count());

            // Kanban-ul (§24.3, fluxul 3) are nevoie de etape terminale.
            $this->assertTrue(Stage::query()->where('is_won', true)->exists());
            $this->assertTrue(Stage::query()->where('is_lost', true)->exists());

            // „My accounts" al Agentului demo (US-CRM-02, fluxul RBAC din §24.3) nu e gol la
            // nicio scară — garanția din AccountsAndContactsSeeder, nu noroc statistic.
            $this->assertGreaterThanOrEqual(5, Account::query()->where('owner_user_id', $agent->getKey())->count());

            // ID-urile scrise în bloc au aceeași formă ca cele din Eloquent (litere mici) — vezi
            // DemoId. Cu majuscule, orice comparație după `Str::lower` pierdea rândul semănat.
            foreach ([Account::class, Deal::class, Order::class] as $model) {
                $this->assertSame(0, $model::query()->whereRaw("id ~ '[A-Z]'")->count(), "{$model}: id-uri cu majuscule.");
            }
        });
    }

    public function test_the_scale_must_be_a_fraction_of_the_full_dataset(): void
    {
        $this->artisan('demo:seed-volume', ['--scale' => '0'])->assertFailed();
        $this->artisan('demo:seed-volume', ['--scale' => '2'])->assertFailed();

        $this->assertSame(0, Tenant::query()->count());
    }
}
