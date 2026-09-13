<?php

namespace Tests\Feature\Help;

use App\Models\User;
use Tests\TestCase;

/**
 * BR-HELP-02 — `POST /hints/{key}`: autentificare, validarea formatului cheii,
 * idempotență, izolare PER UTILIZATOR (nu per tenant — vezi docblock-ul
 * `HintController`). Cerere HTTP reală prin lanțul de middleware (`auth` +
 * `session.context`), nu apel direct la controller.
 */
class DismissHintTest extends TestCase
{
    public function test_a_guest_cannot_dismiss_a_hint(): void
    {
        $this->post('/hints/help-panel-intro')->assertRedirect('/login');
    }

    public function test_a_malformed_key_is_rejected_with_a_422(): void
    {
        $user = $this->makeUser();

        // Spații, majuscule, punctuație — nimic din regexul `^[a-z0-9]+(-[a-z0-9]+)*$`.
        $this->actingAs($user)
            ->postJson('/hints/'.rawurlencode('Not A Valid Key!'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('key');
    }

    public function test_dismissing_a_hint_persists_it_on_the_user(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/hints/help-panel-intro')->assertRedirect();

        $this->assertSame(['help-panel-intro'], $user->refresh()->dismissed_hints);
    }

    public function test_dismissing_the_same_hint_twice_does_not_duplicate_it(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/hints/help-panel-intro');
        $this->actingAs($user)->post('/hints/help-panel-intro');

        $this->assertSame(['help-panel-intro'], $user->refresh()->dismissed_hints);
    }

    public function test_dismissing_a_new_hint_keeps_previously_dismissed_ones(): void
    {
        $user = $this->makeUser(['dismissed_hints' => ['some-older-hint']]);

        $this->actingAs($user)->post('/hints/help-panel-intro');

        $this->assertSame(['some-older-hint', 'help-panel-intro'], $user->refresh()->dismissed_hints);
    }

    public function test_dismissing_a_hint_does_not_affect_other_users(): void
    {
        $dismisser = $this->makeUser();
        $bystander = $this->makeUser();

        $this->actingAs($dismisser)->post('/hints/help-panel-intro');

        $this->assertSame(['help-panel-intro'], $dismisser->refresh()->dismissed_hints);
        $this->assertSame([], $bystander->refresh()->dismissed_hints ?? []);
    }

    /**
     * Un utilizator fără niciun tenant e suficient aici: `users` n-are `tenant_id`
     * și n-are RLS (§19.1) — indiciul respins e o preferință de persoană, nu de
     * organizație (la fel ca `users.theme`, FR-PREF-02), deci nu are nevoie de
     * `TenantContext::run()` ca `makeMember()`.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeUser(array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Help Hint Tester',
            'email' => 'hint.tester.'.uniqid().'@throughput.dev',
            'password' => 'password',
        ], $attributes));
    }
}
