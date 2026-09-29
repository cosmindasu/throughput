<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Database\Factories\DealFactory;
use Database\Factories\PipelineFactory;
use Database\Factories\StageFactory;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `User::can()` memoizează verificările de permisiune fără model (vezi docblock-ul de
 * acolo pentru măsurători: 212 ms → 0,4 ms pe 600 de apeluri).
 *
 * Testele de aici nu verifică VITEZA — verifică faptul că memo-ul nu poate răspunde greșit.
 * Fiecare dintre ele ar trece cu un memo naiv, în afară de cel pe care îl țintește; luate
 * împreună închid cele trei feluri în care o memoizare de autorizare devine o breșă:
 * confuzie între rânduri, confuzie între tenanți, și o schimbare de rol care nu se vede.
 */
class PermissionMemoTest extends TestCase
{
    /**
     * Cel mai important test din fișier.
     *
     * `DealPolicy::update()` e „permisiune ȘI e deal-ul tău". Partea a doua TREBUIE să
     * difere de la rând la rând. Dacă memo-ul ar prinde și verificările cu model, un Agent
     * ar primi drepturi pe deal-urile altcuiva — optimizarea ar deveni escaladare de
     * privilegii, tăcută, pe fiecare listă din aplicație.
     */
    public function test_checks_that_carry_a_model_are_never_memoised(): void
    {
        $tenant = $this->makeTenant('memo');
        $agent = $this->makeMember($tenant, 'agent@memo.test', Permissions::AGENT);
        $other = $this->makeMember($tenant, 'other@memo.test', Permissions::AGENT);

        [$own, $someoneElses] = TenantContext::run($tenant, function () use ($agent, $other) {
            $pipeline = (new PipelineFactory)->create();
            $stage = (new StageFactory)->create(['pipeline_id' => $pipeline->id, 'name' => 'New', 'position' => 1]);
            $account = (new AccountFactory)->create(['created_by' => $agent->getKey()]);

            $make = fn (User $owner) => (new DealFactory)->create([
                'account_id' => $account->id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'owner_user_id' => $owner->getKey(),
                'created_by' => $owner->getKey(),
            ]);

            return [$make($agent), $make($other)];
        });

        $this->actingAs($agent);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

        TenantContext::run($tenant, function () use ($agent, $own, $someoneElses): void {
            // `$user->can($ability, $model)` — EXACT seam-ul pe care `User::can()` îl
            // suprascrie, și exact forma folosită per rând de `ContactResource`,
            // `SavedViewResource` și `StageResource`. `Gate::allows()` NU trece pe aici
            // (intră direct în policy), deci un test scris pe el ar fi trecut liniștit
            // și cu memoizarea pornită pe verificările cu model — verificat prin mutație.
            $this->assertTrue($agent->can('update', $own), 'Agentul trebuie să poată edita deal-ul propriu.');
            $this->assertFalse(
                $agent->can('update', $someoneElses),
                'Al doilea deal a primit răspunsul primului: memo-ul a prins o verificare CU model.'
            );

            // Și invers — un memo care reține primul răspuns ar pica doar într-un sens.
            $this->assertFalse($agent->can('update', $someoneElses));
            $this->assertTrue($agent->can('update', $own));

            // Capătul de lanț: și prin Gate rezultatul rămâne per rând.
            $this->assertTrue(Gate::allows('update', $own));
            $this->assertFalse(Gate::allows('update', $someoneElses));
        });
    }

    /**
     * `config/permission.php` are `'teams' => true`: același om poate fi Owner într-o
     * organizație și Viewer în alta, iar tenantul curent se schimbă chiar în timpul unei
     * cereri (`UpdateMemberRoleAction`, `RevokeInvitationAction`, joburile de sistem).
     * De-aia cheia memo-ului conține tenantul, nu doar numele permisiunii.
     *
     * **Ce NU pretinde testul ăsta — verificat prin mutație, nu presupus.** Scoțând
     * tenantul din cheie, testul CONTINUĂ să treacă. Motivul e că `unsetRelation('roles')`
     * de mai jos golește oricum memo-ul (vezi cârligele din `User`), deci cheia nu apucă
     * să conteze. Și nu e un viciu doar al testului: cu cârligele acelea pe loc, orice
     * reîncărcare a relației trece prin `setRelation`/`unsetRelation` și curăță memo-ul,
     * deci AZI tenantul din cheie e centură peste bretele, nu piesa portantă.
     *
     * Rămâne fiindcă e gratuit și fiindcă apără de o schimbare de versiune: în ziua în care
     * spatie ar reevalua rolurile la `setPermissionsTeamId()` fără să atingă relația,
     * memo-ul ar deveni EL sursa de învechire. Cheia îl împiedică din start.
     *
     * Testul rămâne util ca gardă de regresie end-to-end: un utilizator cu roluri diferite
     * în doi tenanți nu primește niciodată răspunsul celuilalt. `unsetRelation` e explicit
     * fiindcă un simplu `setPermissionsTeamId()` pe o instanță cu `roles` deja încărcată NU
     * reevaluează nimic — comportament al librăriei, identic cu memo-ul dezactivat, deci
     * nici cauzat, nici reparat aici.
     */
    public function test_the_memo_does_not_serve_another_tenants_answer(): void
    {
        $ownerTenant = $this->makeTenant('memo-owner');
        $viewerTenant = $this->makeTenant('memo-viewer');

        $user = $this->makeMember($ownerTenant, 'both@memo.test', Permissions::OWNER);
        $this->makeMember($viewerTenant, 'both@memo.test', Permissions::VIEWER, $user);

        $registrar = app(PermissionRegistrar::class);

        $registrar->setPermissionsTeamId($ownerTenant->getKey());
        $user->unsetRelation('roles');
        $this->assertTrue($user->can('deals.edit'), 'Owner în primul tenant.');

        $registrar->setPermissionsTeamId($viewerTenant->getKey());
        $user->unsetRelation('roles');
        $this->assertFalse(
            $user->can('deals.edit'),
            'A răspuns cu drepturile tenantului anterior — memo-ul nu e cheiat pe tenant.'
        );

        // Înapoi, ca să se vadă că nu e doar „al doilea apel dă mereu fals".
        $registrar->setPermissionsTeamId($ownerTenant->getKey());
        $user->unsetRelation('roles');
        $this->assertTrue($user->can('deals.edit'));
    }

    /**
     * Invalidarea: fiecare mutator din `HasRoles` cheamă `forgetCachedPermissions()`, pe
     * care `User` îl suprascrie ca să golească și memo-ul. Fără asta, o retrogradare ar
     * rămâne invizibilă pentru tot restul cererii.
     */
    public function test_a_role_change_on_the_same_instance_is_observed(): void
    {
        $tenant = $this->makeTenant('memo-demote');
        $user = $this->makeMember($tenant, 'demoted@memo.test', Permissions::OWNER);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

        $this->assertTrue($user->can('deals.edit'), 'Owner, înainte de retrogradare.');

        $user->syncRoles([Permissions::VIEWER]);

        $this->assertFalse(
            $user->can('deals.edit'),
            'Memo-ul a supraviețuit unui `syncRoles()` — o retrogradare rămâne fără efect.'
        );
    }

    /**
     * Plasa de siguranță de bază: memoizat sau nu, răspunsul trebuie să fie cel pe care
     * l-ar da Gate-ul. Un memo care răspunde CONSECVENT greșit ar trece testele de mai sus.
     */
    public function test_the_memoised_answer_matches_a_fresh_instance(): void
    {
        $tenant = $this->makeTenant('memo-parity');
        $user = $this->makeMember($tenant, 'manager@memo.test', Permissions::MANAGER);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

        foreach (['deals.edit', 'deals.delete', 'settings.view', 'bulk.write', 'activity_log.view'] as $permission) {
            $memoised = $user->can($permission);
            $memoisedAgain = $user->can($permission);

            // Instanță nouă = memo gol = drumul complet prin Gate.
            $fresh = User::query()->findOrFail($user->getKey())->can($permission);

            $this->assertSame($fresh, $memoised, "Primul răspuns pentru `{$permission}` diferă de Gate.");
            $this->assertSame($fresh, $memoisedAgain, "Al doilea răspuns pentru `{$permission}` diferă de Gate.");
        }
    }
}
