<?php

namespace Tests\Feature\I18n;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * ADR-022, specs.md §15.8 FR-I18N-04 („mesaje de validare") — cele DOUĂ straturi de mesaje
 * de formular respectă `users.locale`:
 *
 *  1. mesajele GENERICE ale framework-ului (`lang/{en,fr}/validation.php`), publicate din
 *     `vendor/` fiindcă Laravel livrează doar engleză — fără ele, orice „câmp obligatoriu"
 *     apărea în engleză pe o interfață altfel complet franceză;
 *  2. suprascrierile per formular scrise de noi (`lang/{en,fr}/forms.php`), returnate din
 *     `FormRequest::messages()`.
 *
 * Trece prin lanțul REAL de middleware, ca `FlashMessageLocaleTest` — nu prin
 * `App::setLocale()` direct. Contează aici mai mult decât oriunde: `FormRequest::rules()`
 * și `messages()` se evaluează la REZOLVAREA controllerului, adică la capătul pipeline-ului
 * de middleware, deci testul dovedește și că `SetLocale` apucă să ruleze înainte.
 *
 * Aserțiunile franceze sunt pe text LITERAL, nu prin `__()`: altfel ar compara catalogul cu
 * el însuși. Perechea engleză a fiecăreia e asertată alături, în același test, fiindcă
 * publicarea unui fișier de framework e exact genul de operație care poate schimba engleza
 * din greșeală.
 */
class ValidationMessageLocaleTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();
    }

    public function test_a_generic_framework_message_renders_in_french(): void
    {
        // `name` n-are suprascriere în `forms.php` — deci mesajul vine din `validation.php`,
        // fișierul care până acum nu exista deloc în franceză. Ăsta e chiar golul reparat.
        // Numele câmpului vine din `validation.attributes` (I18N-01): „nom", nu „name".
        $this->speaksFrench();

        $this->actingAs($this->owner)
            ->from('/marlin/accounts/create')
            ->post('/marlin/accounts', ['status' => 'prospect', 'credit_terms' => 'net_30'])
            ->assertSessionHasErrors(['name' => 'Le champ nom est obligatoire.']);
    }

    public function test_the_same_generic_message_stays_english_by_default(): void
    {
        $this->actingAs($this->owner)
            ->from('/marlin/accounts/create')
            ->post('/marlin/accounts', ['status' => 'prospect', 'credit_terms' => 'net_30'])
            ->assertSessionHasErrors(['name' => 'The name field is required.']);
    }

    public function test_a_per_form_override_renders_in_french(): void
    {
        $this->speaksFrench();

        $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->post('/marlin/settings/members/invite', ['email' => '', 'role' => Permissions::AGENT])
            ->assertSessionHasErrors(['email' => 'Saisissez l’adresse e-mail à inviter.']);
    }

    public function test_the_same_per_form_override_stays_english_by_default(): void
    {
        $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->post('/marlin/settings/members/invite', ['email' => '', 'role' => Permissions::AGENT])
            ->assertSessionHasErrors(['email' => 'Enter the email address to invite.']);
    }

    /**
     * Proba că suprascrierea CHIAR e cea care câștigă, nu doar că amândouă sunt franceze:
     * pe același câmp, aceeași cerere, `email.email` (suprascris în `forms.php`) trebuie să
     * dea textul nostru, nu pe cel generic din `validation.php`.
     */
    public function test_a_per_form_override_wins_over_the_generic_message(): void
    {
        $this->speaksFrench();

        $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->post('/marlin/settings/members/invite', ['email' => 'nu-e-un-email', 'role' => Permissions::AGENT])
            ->assertSessionHasErrors(['email' => 'Saisissez une adresse e-mail valide.'])
            // Mesajul generic pe aceeași regulă, care ar fi apărut fără suprascriere.
            ->assertSessionDoesntHaveErrors(['email' => 'Le champ email doit être une adresse e-mail valide.']);
    }

    private function speaksFrench(): void
    {
        $this->owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();
    }
}
