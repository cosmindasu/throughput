<?php

namespace Tests\Feature\I18n;

use App\Models\Account;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * ADR-022, specs.md §15.8 FR-I18N-04, plan-implementare.md „Lot I18N" Val 2 — mesajele
 * flash de succes/eroare/notificare (`lang/{en,fr}/flash.php`) respectă `users.locale`,
 * prin lanțul REAL de middleware (`SetLocale`), pe modelul `LocaleTest`/`OrderRuleTranslationTest`
 * — nu prin `App::setLocale()` chemat direct în test.
 */
class FlashMessageLocaleTest extends TestCase
{
    private Tenant $marlin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->clearDatabaseTenantContext();
    }

    public function test_a_flash_message_renders_in_french_for_a_french_speaking_user(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->post('/marlin/accounts', $this->minimalAccountPayload())
            ->assertRedirect()
            ->assertSessionHas('success', 'Compte créé.');
    }

    public function test_the_same_flash_message_stays_in_english_by_default(): void
    {
        // Regresie simetrică (plan-implementare.md, Val 5, livrabile) — un utilizator FĂRĂ
        // preferință explicită (implicit `en`) vede tot engleza de dinainte de mutarea
        // mesajelor în catalog.
        $owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->post('/marlin/accounts', $this->minimalAccountPayload())
            ->assertRedirect()
            ->assertSessionHas('success', 'Account created.');
    }

    /**
     * @return array<string, string>
     */
    private function minimalAccountPayload(): array
    {
        return [
            'name' => 'Northwind Industrial Supply LLC',
            'status' => 'prospect',
            'credit_terms' => 'net_30',
        ];
    }

    /**
     * `App\Models\Stage::deletionBlockedReason()` — pluralizare `trans_choice()`, verificată
     * pe DOUĂ numărători diferite (1 și 2), în franceză: dacă cineva ar reintroduce
     * `Str::plural()`/un ternar pe `=== 1`, engleza ar rămâne corectă din întâmplare, dar
     * gramatica franceză de mai jos ar rămâne neschimbată doar la coincidență, nu prin
     * mecanism — testul verifică FORMA corectă la ambele numărători, nu doar prezența cheii.
     */
    public function test_stage_deletion_blocked_message_pluralizes_correctly_in_french(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $owner->forceFill(['locale' => 'fr'])->save();

        [$pipeline, $stage] = TenantContext::run($this->marlin, function (): array {
            $pipeline = Pipeline::query()->create(['name' => 'Sales', 'is_default' => true]);
            $stage = Stage::query()->create(['pipeline_id' => $pipeline->getKey(), 'name' => 'New', 'position' => 1]);

            return [$pipeline, $stage];
        });

        $this->clearDatabaseTenantContext();
        $this->attachDeal($stage, $owner, 'Deal one');
        $this->clearDatabaseTenantContext();

        // Un singur deal — franceza tratează 1 la fel ca engleza (forma singular).
        $this->actingAs($owner)
            ->delete("/marlin/pipeline/stages/{$stage->id}")
            ->assertRedirect()
            ->assertSessionHas('error', 'Cette étape contient 1 affaire. Déplacez-la vers une autre étape avant de la supprimer.');

        $this->attachDeal($stage, $owner, 'Deal two');
        $this->clearDatabaseTenantContext();

        // Doi deals — forma plurală, distinctă de cea de mai sus („affaires", „Déplacez-les").
        $this->actingAs($owner)
            ->delete("/marlin/pipeline/stages/{$stage->id}")
            ->assertRedirect()
            ->assertSessionHas('error', 'Cette étape contient 2 affaires. Déplacez-les vers une autre étape avant de la supprimer.');
    }

    /**
     * Capcana centrală a lotului, citată verbatim în task — franceza tratează 0 ca
     * SINGULAR, engleza ca plural (`Illuminate\Translation\MessageSelector::getPluralIndex()`,
     * cazul `fr`: `(($number == 0) || ($number == 1)) ? 0 : 1`). Niciun site real din
     * acest lot nu declanșează vreodată `trans_choice()` cu `count = 0` — fiecare apel e
     * gardat de un `if ($count > 0)` mai sus în cod (un „zero" arată un mesaj DIFERIT, nu
     * o formă pluralizată a aceluiași mesaj) — deci testul verifică mecanismul direct, pe
     * cheile catalogului, nu un flux HTTP care nu există.
     */
    public function test_french_treats_zero_as_singular_where_english_treats_it_as_plural(): void
    {
        app()->setLocale('fr');
        $this->assertSame(
            '0 affaire',
            trans_choice('flash.accounts.deletion_blocked_deals_clause', 0, ['count' => 0]),
        );

        app()->setLocale('en');
        $this->assertSame(
            '0 deals',
            trans_choice('flash.accounts.deletion_blocked_deals_clause', 0, ['count' => 0]),
        );
    }

    private function attachDeal(Stage $stage, User $owner, string $title): Deal
    {
        return TenantContext::run($this->marlin, function () use ($stage, $owner, $title): Deal {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $owner->getKey();
            $account->save();

            $deal = new Deal([
                'account_id' => $account->getKey(),
                'pipeline_id' => $stage->pipeline_id,
                'stage_id' => $stage->getKey(),
                'owner_user_id' => $owner->getKey(),
                'title' => $title,
                'value' => 1_000,
                'status' => Deal::STATUS_OPEN,
            ]);
            $deal->created_by = $owner->getKey();
            $deal->save();

            return $deal;
        });
    }
}
