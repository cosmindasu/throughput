<?php

namespace Tests\Feature\I18n;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * ADR-022, specs.md §15.8 FR-I18N-04/06 — cele trei goluri rămase după Valul 3 al Lotului
 * I18N: `ActivityLogResource::actionLabel` (jurnalul tenant-ului), `ActivityEntryResource::
 * description()` (feed-ul dashboard-ului) și `AccountActivityTimeline` (tab-ul „Activity"
 * al unui cont) compuneau text englez direct în PHP (`Str::headline()`/concatenare), în
 * afara catalogului — invizibil la comutarea pe franceză.
 *
 * Pe modelul `FlashMessageLocaleTest`: lanțul REAL de middleware (`SetLocale`), `users.locale`
 * setat pe utilizator, NICIODATĂ `App::setLocale()` chemat direct în test — altfel testul
 * verifică doar catalogul, nu drumul prin care locale-ul ajunge acolo.
 */
class ActivityLocaleTest extends TestCase
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

    public function test_the_tenant_journal_action_label_renders_in_french_for_a_french_speaking_user(): void
    {
        $this->owner->forceFill(['locale' => 'fr'])->save();

        TenantContext::run($this->marlin, function (): void {
            ActivityLog::query()->create([
                'user_id' => $this->owner->getKey(),
                'action' => 'login_failed',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
            ]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/activity')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Activity/Index')
                ->where('entries.data.0.actionLabel', 'Échec de connexion'));
    }

    public function test_the_tenant_journal_action_label_stays_in_english_by_default(): void
    {
        // Regresie simetrică (plan „Lot I18N", livrabile) — un utilizator FĂRĂ preferință
        // explicită (implicit `en`) vede EXACT ce producea `Str::headline('login_failed')`
        // înainte de mutarea pe catalog: „Login Failed", caracter cu caracter.
        TenantContext::run($this->marlin, function (): void {
            ActivityLog::query()->create([
                'user_id' => $this->owner->getKey(),
                'action' => 'login_failed',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
            ]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/activity')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Activity/Index')
                ->where('entries.data.0.actionLabel', 'Login Failed'));
    }

    /**
     * Filtrul de acțiune randa până acum valoarea BRUTĂ a coloanei (`login_failed`), deci
     * nici tradusă, nici măcar engleză corectă — singurul loc din lot unde repararea CHIAR
     * schimbă engleza vizibilă, nu doar franceza. De aici cele două aserțiuni de mai jos:
     * una pe noua engleză, una pe franceză.
     *
     * `value` NU se traduce: merge în query string (`?action=login_failed`), iar o valoare
     * tradusă ar rupe filtrarea pe franceză. Asertat explicit, fiindcă e exact genul de
     * lucru pe care o „traducere completă" l-ar strica din bune intenții.
     */
    public function test_the_action_filter_options_carry_a_translated_label_and_a_stable_value(): void
    {
        // Array-ul ÎNTREG, nu un rând: asta blochează ordinea, numărul ȘI toate etichetele
        // deodată. Dacă migrația adaugă vreodată o valoare în enum, testul pică aici și
        // spune exact ce lipsește — mai util decât un `has('actions', 9)` care ar trece
        // verde cu o etichetă netradusă înăuntru.
        $this->actingAs($this->owner)->get('/marlin/activity')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Activity/Index')
                ->where('actions', [
                    ['value' => 'created', 'label' => 'Created'],
                    ['value' => 'updated', 'label' => 'Updated'],
                    ['value' => 'deleted', 'label' => 'Deleted'],
                    ['value' => 'login', 'label' => 'Login'],
                    ['value' => 'login_failed', 'label' => 'Login Failed'],
                    ['value' => 'exported', 'label' => 'Exported'],
                    ['value' => 'imported', 'label' => 'Imported'],
                    ['value' => 'bulk_action', 'label' => 'Bulk Action'],
                    ['value' => 'role_changed', 'label' => 'Role Changed'],
                ]));

        $this->owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/activity')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Activity/Index')
                ->where('actions', [
                    ['value' => 'created', 'label' => 'Créé'],
                    ['value' => 'updated', 'label' => 'Mis à jour'],
                    ['value' => 'deleted', 'label' => 'Supprimé'],
                    ['value' => 'login', 'label' => 'Connexion'],
                    ['value' => 'login_failed', 'label' => 'Échec de connexion'],
                    ['value' => 'exported', 'label' => 'Exporté'],
                    ['value' => 'imported', 'label' => 'Importé'],
                    ['value' => 'bulk_action', 'label' => 'Action groupée'],
                    ['value' => 'role_changed', 'label' => 'Rôle modifié'],
                ]));
    }

    public function test_the_dashboard_activity_feed_description_renders_in_french(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);
        $agent->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        TenantContext::run($this->marlin, function () use ($agent): void {
            ActivityLog::query()->create([
                'user_id' => $agent->getKey(),
                'action' => 'exported',
                'auditable_type' => Account::class,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
            ]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)->get('/marlin/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('activity.0.description', 'Export : Compte'));
    }

    public function test_the_dashboard_activity_feed_description_stays_in_english_by_default(): void
    {
        // Reia exact scenariul din `DashboardTest::test_the_activity_feed_follows_the_...`
        // (Agent, acțiune `exported` pe `Account`) — proba că mutarea pe catalog n-a
        // schimbat nici măcar un caracter din engleza deja acoperită de testul acela.
        $agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->marlin, function () use ($agent): void {
            ActivityLog::query()->create([
                'user_id' => $agent->getKey(),
                'action' => 'exported',
                'auditable_type' => Account::class,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
            ]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)->get('/marlin/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('activity.0.description', 'Exported Account'));
    }

    /**
     * FR-I18N-06 — proba centrală a granierii traducere/conținut: titlul deal-ului e SCRIS
     * DE UTILIZATOR, deci apare NEATINS, caracter cu caracter, în mijlocul frazei franceze —
     * doar rama („Affaire créée : …") e tradusă, niciodată conținutul din jurul ei.
     */
    public function test_the_account_timeline_translates_the_frame_but_leaves_the_deal_title_untouched(): void
    {
        $this->owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $account = TenantContext::run($this->marlin, function (): Account {
            $account = (new AccountFactory)->create(['created_by' => $this->owner->getKey()]);

            $pipeline = Pipeline::query()->create(['name' => 'Standard']);
            $stage = Stage::query()->create(['pipeline_id' => $pipeline->getKey(), 'name' => 'Qualification', 'position' => 1]);

            // Titlu deliberat NE-francez, ca să nu se poată confunda cu o traducere —
            // dacă mecanismul l-ar traduce din greșeală, testul l-ar prinde imediat.
            $deal = new Deal([
                'account_id' => $account->getKey(),
                'pipeline_id' => $pipeline->getKey(),
                'stage_id' => $stage->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'title' => 'Northwind Renewal — DO NOT TRANSLATE',
                'status' => Deal::STATUS_OPEN,
            ]);
            $deal->created_by = $this->owner->getKey();
            $deal->save();

            return $account;
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/accounts/{$account->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred
                    ->where('activity.0.description', 'Affaire créée : Northwind Renewal — DO NOT TRANSLATE')));
    }
}
