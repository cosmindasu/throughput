<?php

namespace Database\Seeders\Demo;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\MembershipFactory;
use Database\Seeders\Support\DemoStaffNames;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cei 4 conturi demo cu emailurile EXACTE din specs.md §4.2, plus colegi obișnuiți per
 * tenant (task brief pct. b) — ca listele de asignare să nu aibă un singur nume. Owner e
 * membru în toate cele 3 organizații (FR-TEN-01); Manager/Agent/Viewer doar în Marlin.
 *
 * Membership-urile se scriu ÎN interiorul `TenantContext::run()` al apelantului: politica
 * RLS proprie a `memberships` (ADR-014, pct. 2) cere `app.tenant_id` sau `app.user_id`
 * setat chiar și pentru INSERT.
 */
final class UsersAndMembershipsSeeder
{
    /**
     * @param  array<string, Tenant>  $tenants
     * @param  array<string, array<string, mixed>>  $configs
     * @return array<string, array{owner_id: string, demo_agent_id: ?string, pool: list<array{id: string, role: string}>}>
     */
    public function run(array $tenants, array $configs): array
    {
        $password = Hash::make('password');

        // Pool-ul de nume se ține pe TOT seed-ul, nu per tenant: Owner-ul e membru în toate
        // trei și comută între ele din bara de sus.
        DemoStaffNames::reset();

        $owner = User::updateOrCreate(['email' => 'demo.owner@throughput.dev'], ['name' => 'Olivia Sterling', 'password' => $password, 'email_verified_at' => now()]);
        $manager = User::updateOrCreate(['email' => 'demo.manager@throughput.dev'], ['name' => 'Marcus Reyes', 'password' => $password, 'email_verified_at' => now()]);
        $agent = User::updateOrCreate(['email' => 'demo.agent@throughput.dev'], ['name' => 'Priya Anand', 'password' => $password, 'email_verified_at' => now()]);
        $viewer = User::updateOrCreate(['email' => 'demo.viewer@throughput.dev'], ['name' => 'Evelyn Cho', 'password' => $password, 'email_verified_at' => now()]);

        $result = [];

        foreach ($configs as $slug => $config) {
            $tenant = $tenants[$slug];
            $isPrimary = $slug === 'marlin';
            $pool = [];

            TenantContext::run($tenant, function () use ($owner, $manager, $agent, $viewer, $isPrimary, $slug, &$pool): void {
                $this->addMembership($owner->id);
                $pool[] = ['id' => $owner->id, 'role' => Permissions::OWNER];

                if ($isPrimary) {
                    $this->addMembership($manager->id);
                    $this->addMembership($agent->id);
                    $this->addMembership($viewer->id);
                    $pool[] = ['id' => $manager->id, 'role' => Permissions::MANAGER];
                    $pool[] = ['id' => $agent->id, 'role' => Permissions::AGENT];
                    $pool[] = ['id' => $viewer->id, 'role' => Permissions::VIEWER];
                }

                // Colegi obișnuiți (task brief pct. b) — fiecare tenant are propriul personal
                // local, ca "owner_user_id" pe conturi/deals/comenzi să nu se reducă la un
                // singur nume (Cascade/Northgate nu au Manager/Agent/Viewer demo proprii).
                $colleagueSpec = $isPrimary
                    ? [Permissions::MANAGER => 1, Permissions::AGENT => 4]
                    : [Permissions::MANAGER => 1, Permissions::AGENT => 3, Permissions::VIEWER => 1];

                $n = 0;
                foreach ($colleagueSpec as $role => $count) {
                    for ($k = 1; $k <= $count; $k++) {
                        $n++;
                        // NU `fake()->name()`: lipea titluri („Prof. Keegan Wilderman III"),
                        // iar numele astea stau lângă cele patru personaje demo scrise de mână.
                        $name = DemoStaffNames::next();
                        $local = strtolower((string) preg_replace('/[^a-z]+/', '.', strtolower($name)));
                        $local = trim($local, '.');
                        $email = "{$local}.{$n}@{$slug}.internal";

                        $colleague = User::create([
                            'name' => $name,
                            'email' => $email,
                            'password' => Hash::make('password'),
                            'email_verified_at' => now(),
                        ]);

                        $this->addMembership($colleague->id);
                        $pool[] = ['id' => $colleague->id, 'role' => $role];
                    }
                }
            });

            $result[$slug] = [
                'owner_id' => $owner->id,
                'demo_agent_id' => $isPrimary ? $agent->id : null,
                'pool' => $pool,
            ];
        }

        return $result;
    }

    private function addMembership(string $userId): void
    {
        $row = (new MembershipFactory)->definition();
        $row['user_id'] = $userId;

        Membership::create($row);
    }

    /**
     * @param  array<string, Tenant>  $tenants
     * @param  array<string, array{owner_id: string, demo_agent_id: ?string, pool: list<array{id: string, role: string}>}>  $usersByTenant
     */
    public function assignRoles(array $tenants, array $usersByTenant): void
    {
        $registrar = app(PermissionRegistrar::class);

        foreach ($usersByTenant as $slug => $data) {
            $registrar->setPermissionsTeamId($tenants[$slug]->getKey());

            foreach ($data['pool'] as $member) {
                User::find($member['id'])?->assignRole($member['role']);
            }
        }

        $registrar->setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();
    }
}
