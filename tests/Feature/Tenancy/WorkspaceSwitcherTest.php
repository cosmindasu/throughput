<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan §7.9 — al treilea test „care ar fi prins un bug real".
 *
 * Sub politica RLS uniformă, comutatorul de spațiu de lucru (FR-TEN-01) e imposibil de
 * construit: interogarea „în ce organizații sunt membru" e cross-tenant prin natura ei, iar
 * un utilizator membru în două organizații vede 0 rânduri fără context și 1 cu contextul
 * primului — al doilea workspace devine nedescoperibil. De aici politica proprie a tabelei
 * `memberships` (ADR-014, pct. 2), pe `app.user_id`.
 *
 * Testul verifică AMBELE jumătăți ale afirmației: că se vede ce trebuie ȘI că nu se vede
 * ce nu trebuie. Prima singură ar fi trecut și cu politica scoasă complet.
 */
class WorkspaceSwitcherTest extends TestCase
{
    public function test_a_member_of_two_organisations_sees_both_in_the_switcher(): void
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');

        $owner = $this->makeMember($marlin, 'demo.owner@throughput.dev');
        $this->makeMember($cascade, 'demo.owner@throughput.dev', user: $owner);

        $this->clearDatabaseTenantContext();

        // Exact secvența din cerere: autentificat, dar fără workspace rezolvat încă.
        TenantContext::openFor($owner->getKey(), function () use ($owner): void {
            $workspaces = Membership::forCurrentUserAcrossTenants($owner->getKey());

            $this->assertCount(2, $workspaces);
            $this->assertEqualsCanonicalizing(
                ['Cascade Hydraulic Components', 'Marlin Fasteners & Supply Co.'],
                $workspaces->map(fn (Membership $m) => $m->tenant->name)->all()
            );
        });
    }

    public function test_a_colleague_cannot_see_my_memberships_in_other_organisations(): void
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');

        $owner = $this->makeMember($marlin, 'demo.owner@throughput.dev');
        $this->makeMember($cascade, 'demo.owner@throughput.dev', user: $owner);
        $colleague = $this->makeMember($marlin, 'demo.manager@throughput.dev');

        $this->clearDatabaseTenantContext();

        TenantContext::openFor($colleague->getKey(), function () use ($marlin, $owner, $cascade): void {
            TenantContext::setTenant($marlin->getKey());

            $visible = DB::table('memberships')->get();

            // Ecranul Members al tenantului curent: colegul + eu. Nu și membership-ul
            // celuilalt în a doua organizație.
            $this->assertCount(2, $visible);
            $this->assertSame([$marlin->getKey()], $visible->pluck('tenant_id')->unique()->values()->all());

            $this->assertSame(0, DB::table('memberships')
                ->where('user_id', $owner->getKey())
                ->where('tenant_id', $cascade->getKey())
                ->count());
        });
    }

    public function test_without_any_context_the_switcher_is_empty_rather_than_full(): void
    {
        $marlin = $this->makeTenant('marlin');
        $this->makeMember($marlin, 'demo.viewer@throughput.dev');

        $this->clearDatabaseTenantContext();

        // Ruta publică, jobul de sistem: cade închis. Dacă politica ar fi greșit scrisă
        // (de exemplu cu `OR true` ca să „meargă comutatorul"), aici s-ar vedea tot.
        $this->assertSame(0, DB::table('memberships')->count());
    }
}
