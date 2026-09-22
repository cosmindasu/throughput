<?php

namespace Tests\Feature\I18n;

use App\Http\Resources\PaymentResource;
use App\Http\Resources\Reports\ReportDefinitionResource;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Pipeline;
use App\Models\ReportDefinition;
use App\Models\SavedView;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ADR-022, specs.md §15.8 FR-I18N-04 — verificarea Valului 5 al Lotului I18N a găsit opt
 * locuri unde textul generat server-side rămăsese literal, în engleză, invizibil pentru
 * `php artisan i18n:coverage` (nu sunt chei de catalog lipsă — sunt literale scrise direct
 * în cod; gate-ul n-are ce compara). Fișierul de față acoperă CINCI dintre ele, cele care NU
 * aveau un vecin de test natural existent la prima trecere:
 *
 *   - `App\Http\Resources\PaymentResource::methodLabel` — array PHP hardcodat
 *     ('Bank transfer'/'Check'/'Manual'), acum `enums.payment_method.*`;
 *   - `App\Http\Resources\Reports\ReportDefinitionResource::sourceLabel`, eticheta de
 *     rezervă `?? 'Saved view'`, acum `reports.saved_view_fallback`;
 *   - `App\Services\Search\GlobalSearchService` — cele trei etichete de acțiune rapidă din
 *     `frequentActions()`/`searchActions()` ("Create account"/"Create contact"/
 *     "Create deal"), acum `search.actions.*`;
 *   - `App\Support\Contacts\AccountBelongsToTenant` — regulă reutilizată de Contacts ȘI
 *     Deals, acum `rules.contacts.account_not_in_workspace`;
 *   - `App\Http\Requests\Reports\Store/UpdateReportRequest::withValidator()` — cele două
 *     mesaje de SENS pe `saved_view_id`, acum `rules.reports.saved_view_*`.
 *
 * Restul locurilor găsite de Valul 5 AU un vecin natural existent — aserțiunea franceză s-a
 * adăugat ACOLO, ca aserțiune nouă, nu ca rescriere: `DeactivatedMemberNames` în
 * `tests/Feature/Members/DeactivatedMemberPlaceholderTest`, `already_a_member` și
 * `invitation_already_pending` în `tests/Feature/I18n/MemberRefusalLocaleTest`, corpul
 * întreg din `MemberInvitationMail` în `tests/Feature/Mail/MemberInvitationMailLocaleTest`.
 *
 * A DOUA trecere a Valului 5 a închis cele cinci locuri rămase semnalate, dar nereparate,
 * de verificarea primei treceri — trei dintre ele extind secțiuni deja existente mai jos
 * (etichetele de GRUP și varianta cu termen din `GlobalSearchService`, a treia eroare de
 * SENS pe `saved_view_id`), iar una e nouă (`AccountBelongsToTenant`'s sibling,
 * `rules.contacts.primary_requires_account`, din `StoreContactRequest`).
 *
 * Fiecare aserțiune franceză e însoțită de perechea ei engleză, ca peste tot în acest
 * director — regresie zero pentru un utilizator care n-a atins comutatorul de limbă.
 */
class ServerSideLabelLocaleTest extends TestCase
{
    private Tenant $reportsTenant;

    // -- PaymentResource::methodLabel ---------------------------------------------------

    public function test_payment_method_labels_translate_to_french(): void
    {
        App::setLocale('fr');
        $labels = $this->paymentMethodLabels();
        App::setLocale('en');

        $this->assertSame([
            Payment::METHOD_BANK_TRANSFER => 'Virement bancaire',
            Payment::METHOD_CHECK => 'Chèque',
            Payment::METHOD_MANUAL => 'Manuel',
        ], $labels);
    }

    public function test_payment_method_labels_stay_english_by_default(): void
    {
        $this->assertSame([
            Payment::METHOD_BANK_TRANSFER => 'Bank transfer',
            Payment::METHOD_CHECK => 'Check',
            Payment::METHOD_MANUAL => 'Manual',
        ], $this->paymentMethodLabels());
    }

    /**
     * Fallback `ucfirst()` pentru o metodă viitoare fără intrare în `enums.payment_method`
     * — comportament NESCHIMBAT față de dinainte de Val 5, indiferent de locale.
     */
    public function test_an_unmapped_payment_method_falls_back_to_ucfirst_regardless_of_locale(): void
    {
        $payment = new Payment(['amount' => 10, 'method' => 'stripe', 'paid_at' => now()]);

        App::setLocale('fr');
        $label = (new PaymentResource($payment))->toArray(Request::create('/'))['methodLabel'];
        App::setLocale('en');

        $this->assertSame('Stripe', $label);
    }

    /**
     * @return array<string, string>
     */
    private function paymentMethodLabels(): array
    {
        $labels = [];

        foreach (Payment::methods() as $method) {
            $payment = new Payment(['amount' => 10, 'method' => $method, 'paid_at' => now()]);
            $labels[$method] = (new PaymentResource($payment))->toArray(Request::create('/'))['methodLabel'];
        }

        return $labels;
    }

    // -- ReportDefinitionResource::sourceLabel fallback ---------------------------------

    public function test_the_saved_view_report_fallback_label_translates_to_french(): void
    {
        App::setLocale('fr');
        $label = $this->savedViewFallbackSourceLabel();
        App::setLocale('en');

        $this->assertSame('Vue enregistrée', $label);
    }

    public function test_the_saved_view_report_fallback_label_stays_english_by_default(): void
    {
        $this->assertSame('Saved view', $this->savedViewFallbackSourceLabel());
    }

    /** `savedView` absent (relație `null`) — exact ramura care produce eticheta de rezervă. */
    private function savedViewFallbackSourceLabel(): string
    {
        $report = new ReportDefinition([
            'report_type' => ReportDefinition::TYPE_SAVED_VIEW_EXPORT,
            'name' => 'Untitled export',
            'format' => 'csv',
            'schedule_frequency' => 'none',
            'recipients' => ['ops@example.com'],
            'is_active' => true,
        ]);
        $report->setRelation('createdBy', null);
        $report->setRelation('savedView', null);
        $report->setRelation('latestRun', null);

        return (new ReportDefinitionResource($report))->toArray(Request::create('/'))['sourceLabel'];
    }

    // -- GlobalSearchService quick-action labels ----------------------------------------

    public function test_global_search_quick_action_labels_translate_to_french(): void
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($owner)->getJson('/marlin/search');

        $response->assertOk();
        $this->assertSame(
            ['Créer un compte', 'Créer un contact', 'Créer une affaire'],
            $this->actionLabels($response)
        );
    }

    public function test_global_search_quick_action_labels_stay_english_by_default(): void
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($owner)->getJson('/marlin/search');

        $response->assertOk();
        $this->assertSame(
            ['Create account', 'Create contact', 'Create deal'],
            $this->actionLabels($response)
        );
    }

    /**
     * @return list<string>
     */
    private function actionLabels(TestResponse $response): array
    {
        $group = collect($response->json('groups'))->firstWhere('type', 'actions');

        return collect($group['results'] ?? [])->pluck('label')->all();
    }

    /**
     * FR-I18N-04, Lotul I18N Val 5 (a doua trecere) — `search.actions.create_account_named`,
     * varianta cu termenul tipărit din `GlobalSearchService::searchActions()`
     * (`sprintf('Create account named "%s"', $term)` înainte de acest val), semnalată
     * separat la prima trecere, alături de etichetele de grup de mai jos, reparată acum.
     */
    public function test_global_search_create_account_named_action_translates_to_french(): void
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($owner)->getJson('/marlin/search?q=acme');

        $response->assertOk();
        $this->assertContains('Créer un compte nommé "acme"', $this->actionLabels($response));
    }

    public function test_global_search_create_account_named_action_stays_english_by_default(): void
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($owner)->getJson('/marlin/search?q=acme');

        $response->assertOk();
        $this->assertContains('Create account named "acme"', $this->actionLabels($response));
    }

    /**
     * FR-I18N-04, Lotul I18N Val 5 (a doua trecere) — etichetele de GRUP din dropdown-ul
     * căutării globale (`initialState()`/`search()`), acum `search.groups.*`. „Actions" e
     * identic în ambele limbi (cuvânt existent și în franceză, deja folosit literal peste
     * tot în UI-ul existent — `actionsColumnLabel`, coloane de tabel) — nu e o scăpare a
     * testului, e chiar traducerea corectă.
     */
    public function test_global_search_group_labels_translate_to_french(): void
    {
        [$owner, $tenant] = $this->tenantWithSearchableRecords();
        $owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner);
        $this->withSession([
            'recently_viewed.'.$tenant->getKey() => [[
                'type' => 'account', 'id' => 'x', 'label' => 'Northgate Supply Co.', 'url' => '/marlin/accounts/x',
            ]],
        ]);

        $initial = $this->getJson('/marlin/search');
        $initial->assertOk();
        $this->assertSame('Récent', $this->groupLabel($initial, 'recent'));
        $this->assertSame('Actions', $this->groupLabel($initial, 'actions'));

        $searched = $this->getJson('/marlin/search?q=northgate');
        $searched->assertOk();
        $this->assertSame('Comptes', $this->groupLabel($searched, 'accounts'));
        $this->assertSame('Contacts', $this->groupLabel($searched, 'contacts'));
        $this->assertSame('Affaires', $this->groupLabel($searched, 'deals'));
    }

    public function test_global_search_group_labels_stay_english_by_default(): void
    {
        [$owner, $tenant] = $this->tenantWithSearchableRecords();
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner);
        $this->withSession([
            'recently_viewed.'.$tenant->getKey() => [[
                'type' => 'account', 'id' => 'x', 'label' => 'Northgate Supply Co.', 'url' => '/marlin/accounts/x',
            ]],
        ]);

        $initial = $this->getJson('/marlin/search');
        $initial->assertOk();
        $this->assertSame('Recent', $this->groupLabel($initial, 'recent'));
        $this->assertSame('Actions', $this->groupLabel($initial, 'actions'));

        $searched = $this->getJson('/marlin/search?q=northgate');
        $searched->assertOk();
        $this->assertSame('Accounts', $this->groupLabel($searched, 'accounts'));
        $this->assertSame('Contacts', $this->groupLabel($searched, 'contacts'));
        $this->assertSame('Deals', $this->groupLabel($searched, 'deals'));
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function tenantWithSearchableRecords(): array
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($marlin, function () use ($owner): void {
            $account = new Account(['name' => 'Northgate Supply Co.', 'status' => Account::STATUS_ACTIVE]);
            $account->created_by = $owner->getKey();
            $account->save();

            $contact = new Contact(['account_id' => $account->getKey(), 'first_name' => 'Pat', 'last_name' => 'Northgate']);
            $contact->created_by = $owner->getKey();
            $contact->save();

            $pipeline = Pipeline::query()->create(['name' => 'Standard']);
            $stage = Stage::query()->create(['pipeline_id' => $pipeline->getKey(), 'name' => 'Qualification', 'position' => 1]);
            $deal = new Deal([
                'account_id' => $account->getKey(),
                'pipeline_id' => $pipeline->getKey(),
                'stage_id' => $stage->getKey(),
                'owner_user_id' => $owner->getKey(),
                'title' => 'Northgate annual contract',
                'status' => Deal::STATUS_OPEN,
            ]);
            $deal->created_by = $owner->getKey();
            $deal->save();
        });

        return [$owner, $marlin];
    }

    private function groupLabel(TestResponse $response, string $type): ?string
    {
        $group = collect($response->json('groups'))->firstWhere('type', $type);

        return $group['label'] ?? null;
    }

    // -- AccountBelongsToTenant ----------------------------------------------------------

    public function test_the_account_not_in_workspace_rule_message_translates_to_french(): void
    {
        [$owner, $foreignAccountId] = $this->ownerWithForeignTenantAccount();
        $owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->from('/marlin/contacts/create')
            ->post('/marlin/contacts', $this->contactPayload($foreignAccountId))
            ->assertSessionHasErrors(['account_id' => 'Sélectionnez un compte de cet espace de travail.']);
    }

    public function test_the_account_not_in_workspace_rule_message_stays_english_by_default(): void
    {
        [$owner, $foreignAccountId] = $this->ownerWithForeignTenantAccount();

        $this->actingAs($owner)
            ->from('/marlin/contacts/create')
            ->post('/marlin/contacts', $this->contactPayload($foreignAccountId))
            ->assertSessionHasErrors(['account_id' => 'Select an account from this workspace.']);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function ownerWithForeignTenantAccount(): array
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $owner = $this->makeMember($marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $foreignAccountId = TenantContext::run($cascade, function () use ($cascade, $owner): string {
            $this->makeMember($cascade, 'demo.owner@throughput.dev', Permissions::OWNER, user: $owner);
            $account = new Account(['name' => 'Cascade Bearing Co.']);
            $account->created_by = $owner->getKey();
            $account->save();

            return $account->id;
        });
        $this->clearDatabaseTenantContext();

        return [$owner, $foreignAccountId];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactPayload(string $accountId): array
    {
        return [
            'account_id' => $accountId,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ];
    }

    // -- StoreContactRequest — primary contact requires an account ----------------------

    /**
     * FR-I18N-04, Lotul I18N Val 5 (a doua trecere) — `rules.contacts.primary_requires_account`
     * (`StoreContactRequest::withValidator()`, `is_primary` fără `account_id`), semnalată
     * separat la prima trecere alături de `AccountBelongsToTenant` de mai sus, reparată acum.
     */
    public function test_the_primary_contact_requires_account_rule_message_translates_to_french(): void
    {
        $owner = $this->ownerForContacts();
        $owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->from('/marlin/contacts/create')
            ->post('/marlin/contacts', ['first_name' => 'Jane', 'last_name' => 'Doe', 'is_primary' => true])
            ->assertSessionHasErrors(['is_primary' => 'Un contact principal doit appartenir à un compte.']);
    }

    public function test_the_primary_contact_requires_account_rule_message_stays_english_by_default(): void
    {
        $owner = $this->ownerForContacts();

        $this->actingAs($owner)
            ->from('/marlin/contacts/create')
            ->post('/marlin/contacts', ['first_name' => 'Jane', 'last_name' => 'Doe', 'is_primary' => true])
            ->assertSessionHasErrors(['is_primary' => 'A primary contact must belong to an account.']);
    }

    private function ownerForContacts(): User
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        return $owner;
    }

    // -- Store/UpdateReportRequest — saved_view_id business rules -----------------------

    public function test_a_missing_saved_view_error_translates_to_french(): void
    {
        $owner = $this->frenchSpeakingReportOwner();

        $this->actingAs($owner)
            ->from('/marlin/reports/create')
            ->post('/marlin/reports', $this->savedViewReportPayload((string) Str::ulid()))
            ->assertSessionHasErrors(['saved_view_id' => 'Cette vue enregistrée est introuvable.']);
    }

    public function test_the_same_missing_saved_view_error_stays_english_by_default(): void
    {
        $owner = $this->makeTenantOwnerForReports();

        $this->actingAs($owner)
            ->from('/marlin/reports/create')
            ->post('/marlin/reports', $this->savedViewReportPayload((string) Str::ulid()))
            ->assertSessionHasErrors(['saved_view_id' => 'This saved view could not be found.']);
    }

    public function test_a_forbidden_saved_view_error_translates_to_french(): void
    {
        $owner = $this->frenchSpeakingReportOwner();
        $privateViewId = $this->privateSavedViewOfAnotherMember();

        $this->actingAs($owner)
            ->from('/marlin/reports/create')
            ->post('/marlin/reports', $this->savedViewReportPayload($privateViewId))
            ->assertSessionHasErrors(['saved_view_id' => 'Vous n’avez pas accès à cette vue enregistrée.']);
    }

    public function test_the_same_forbidden_saved_view_error_stays_english_by_default(): void
    {
        $owner = $this->makeTenantOwnerForReports();
        $privateViewId = $this->privateSavedViewOfAnotherMember();

        $this->actingAs($owner)
            ->from('/marlin/reports/create')
            ->post('/marlin/reports', $this->savedViewReportPayload($privateViewId))
            ->assertSessionHasErrors(['saved_view_id' => 'You do not have access to this saved view.']);
    }

    /**
     * FR-I18N-04, Lotul I18N Val 5 (a doua trecere) — `rules.reports.saved_view_unsupported_type`,
     * a treia eroare din ACEEAȘI metodă decât cele două de mai sus, rescrisă la interpolare
     * prin substituent (`:type`) în loc de concatenare manuală. `deals` e vizibil (`SavedViewResourceType::isSupported()`
     * întoarce `true`) dar nu are încă export (`ExportableResources::map()` nu-l listează) —
     * exact ramura care declanșează mesajul, nu `saved_view_forbidden`/`saved_view_not_found`.
     */
    public function test_a_saved_view_of_an_unsupported_type_error_translates_to_french(): void
    {
        $owner = $this->frenchSpeakingReportOwner();
        $dealsViewId = $this->savedViewOfUnsupportedType($owner);

        $this->actingAs($owner)
            ->from('/marlin/reports/create')
            ->post('/marlin/reports', $this->savedViewReportPayload($dealsViewId))
            ->assertSessionHasErrors([
                'saved_view_id' => 'Les vues enregistrées sur "deals" ne peuvent pas encore être utilisées comme source de rapport.',
            ]);
    }

    public function test_the_same_unsupported_type_error_stays_english_by_default(): void
    {
        $owner = $this->makeTenantOwnerForReports();
        $dealsViewId = $this->savedViewOfUnsupportedType($owner);

        $this->actingAs($owner)
            ->from('/marlin/reports/create')
            ->post('/marlin/reports', $this->savedViewReportPayload($dealsViewId))
            ->assertSessionHasErrors([
                'saved_view_id' => 'Saved views on "deals" cannot be used as a report source yet.',
            ]);
    }

    /**
     * O vedere de tip `deals`, VIZIBILĂ pentru Owner-ul tenantului (`visibility` team) — ca
     * validarea de SENS să ajungă la a treia ramură (tip nesuportat de export), nu la
     * `saved_view_forbidden`.
     */
    private function savedViewOfUnsupportedType(User $owner): string
    {
        $tenant = $this->reportsTenant;

        return TenantContext::run($tenant, function () use ($owner): string {
            $view = SavedView::forceCreate([
                'resource_type' => 'deals',
                'name' => 'Deals view',
                'filters' => [],
                'columns' => [],
                'sort' => 'title',
                'visibility' => SavedView::VISIBILITY_TEAM,
                'user_id' => $owner->getKey(),
            ]);

            return $view->getKey();
        });
    }

    private function makeTenantOwnerForReports(): User
    {
        $this->reportsTenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($this->reportsTenant, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        return $owner;
    }

    private function frenchSpeakingReportOwner(): User
    {
        $owner = $this->makeTenantOwnerForReports();
        $owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        return $owner;
    }

    /**
     * O vedere PRIVATĂ a unui ALT membru din același tenant — vizibilă în bază, dar
     * refuzată de `SavedViewPolicy::view()` pentru oricine altcineva, inclusiv un Owner
     * (vizibilitatea decide, nu rolul — vezi docblock-ul `SavedViewPolicy`).
     */
    private function privateSavedViewOfAnotherMember(): string
    {
        $tenant = $this->reportsTenant;
        $otherOwner = $this->makeMember($tenant, 'demo.other-owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        return TenantContext::run($tenant, function () use ($otherOwner): string {
            $view = SavedView::forceCreate([
                'resource_type' => 'accounts',
                'name' => 'Private view',
                'filters' => [],
                'columns' => [],
                'sort' => 'name',
                'visibility' => SavedView::VISIBILITY_PRIVATE,
                'user_id' => $otherOwner->getKey(),
            ]);

            return $view->getKey();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function savedViewReportPayload(string $savedViewId): array
    {
        return [
            'name' => 'Accounts export',
            'report_type' => ReportDefinition::TYPE_SAVED_VIEW_EXPORT,
            'saved_view_id' => $savedViewId,
            'format' => 'csv',
            'schedule_frequency' => 'none',
            'recipients' => ['ops@throughput.dev'],
        ];
    }
}
