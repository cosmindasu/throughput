<?php

namespace Tests\Feature\I18n;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * ADR-022, specs.md §15.8 FR-I18N-04 — refuzurile de pe fluxul de membri (§6.4, BR-TEN-01/02)
 * respectă `users.locale`. Ultima familie de text vizibil care rămăsese integral în engleză
 * după Valul 3: 14 mesaje construite de `App\Policies\MembershipPolicy`,
 * `App\Actions\Members\UpdateMemberRoleAction` și patru `withErrors()` din controllere.
 *
 * Trece prin lanțul REAL de middleware (`SetLocale` citește `users.locale`), ca
 * `FlashMessageLocaleTest` — nu prin `App::setLocale()` chemat direct în test: aici se
 * verifică inclusiv faptul că limba ajunge până în Policy, care rulează la capătul
 * pipeline-ului, nu doar că fișierul de catalog conține cheia.
 *
 * Aserțiunile pe franceză sunt pe textul LITERAL, nu prin `__()` — altfel testul ar compara
 * catalogul cu el însuși și ar trece verde pe orice. Perechea engleză a fiecăruia e deja
 * asertată în `tests/Feature/Members/*` și `tests/Feature/Rbac/MembershipPolicyTest`, tot
 * literal: împreună, cele două seturi sunt garda că extragerea n-a schimbat engleza vizibilă.
 */
class MemberRefusalLocaleTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // Ca în `MemberDeactivationTest`: `.env.testing` are DEMO_MODE=true, iar ruta de
        // dezactivare e păzită de `EnsureDemoModeGuardrails`. Aici se verifică refuzul de
        // AUTORIZARE, nu cel de demo — acela are propriul test.
        config(['throughput.demo.mode' => false]);

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'manager@throughput.dev', Permissions::MANAGER);
        $this->clearDatabaseTenantContext();
    }

    public function test_the_last_owner_refusal_renders_in_french(): void
    {
        $this->speaksFrench($this->owner);

        $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->post('/marlin/settings/members/'.$this->membershipIdOf($this->owner).'/deactivate', ['reassign' => false])
            ->assertSessionHasErrors([
                'deactivate' => 'Transférez la propriété avant de désactiver le dernier Propriétaire.',
            ]);
    }

    public function test_the_same_refusal_stays_english_by_default(): void
    {
        // Regresie simetrică: un utilizator FĂRĂ preferință explicită (implicit `en`) vede
        // exact textul de dinainte de mutarea în catalog, caracter cu caracter.
        $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->post('/marlin/settings/members/'.$this->membershipIdOf($this->owner).'/deactivate', ['reassign' => false])
            ->assertSessionHasErrors([
                'deactivate' => 'Transfer ownership before deactivating the last Owner.',
            ]);
    }

    public function test_an_invitation_refusal_renders_in_french(): void
    {
        $this->speaksFrench($this->manager);

        $this->actingAs($this->manager)
            ->from('/marlin/settings/members')
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::OWNER])
            ->assertSessionHasErrors([
                'role' => 'Seul un Propriétaire peut inviter un autre Propriétaire.',
            ]);
    }

    /**
     * Proba că numele rolului chiar vine din `lang/{locale}/roles.php` prin înlocuitorul
     * `:owner`, nu e scris în fraza din `rules.php` (decizia proprietarului din 2026-09-21,
     * o singură sursă pentru numele rolurilor).
     *
     * O aserțiune pe „Propriétaire" singură n-ar putea distinge cele două variante — ar
     * trece verde și dacă cuvântul era hardcodat în frază. Suprascrierea cheii de rol
     * LA RUNTIME le separă: dacă legătura e vie, fraza urmează; dacă e ruptă, fraza rămâne
     * pe „Propriétaire" și testul pică.
     */
    public function test_the_role_name_inside_a_refusal_follows_the_roles_catalog(): void
    {
        $this->speaksFrench($this->owner);
        Lang::addLines(['roles.owner' => 'Titulaire'], 'fr');

        $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->post('/marlin/settings/members/'.$this->membershipIdOf($this->owner).'/deactivate', ['reassign' => false])
            ->assertSessionHasErrors([
                'deactivate' => 'Transférez la propriété avant de désactiver le dernier Titulaire.',
            ]);
    }

    private function speaksFrench(User $user): void
    {
        $user->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();
    }

    private function membershipIdOf(User $user): string
    {
        return (string) TenantContext::run(
            $this->marlin,
            fn () => Membership::query()->where('user_id', $user->getKey())->firstOrFail()->getKey(),
        );
    }
}
