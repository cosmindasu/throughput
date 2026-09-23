<?php

namespace Tests\Feature\Reports;

use App\Models\ReportDefinition;
use App\Models\SavedView;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use App\Support\SavedViews\SavedViewResourceType;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BR-REP-01 (specs.md §16.1, v1.21 pct. 9): doar vederile salvate pe accounts/contacts/
 * orders — resurse deja exportabile (§13.5, `ExportableResources::map()`) — pot fi sursă a
 * unui raport `saved_view_export`; deals/products (deși EXISTĂ ca `saved_views.resource_type`,
 * `SavedView::RESOURCE_TYPES`) n-au încă export, deci sunt respinse explicit la salvare.
 *
 * Regula e aplicată în DOUĂ locuri, verificate amândouă aici: `StoreReportRequest::withValidator()`
 * (la salvare) și `ReportController::eligibleSavedViews()` (lista oferită formularului, ca
 * să nu propună o alegere pe care serverul ar respinge-o oricum).
 *
 * ZERO acoperire înainte de acest fișier — `ReportSavedViewExportTest` (existent) folosește
 * doar `accounts` și nu atinge niciodată ramura de respingere.
 */
class ReportSavedViewSourceRestrictionTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function permittedResourceTypes(): array
    {
        return [
            ['accounts'],
            ['orders'],
        ];
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function forbiddenResourceTypes(): array
    {
        return [
            ['deals'],
            ['products'],
        ];
    }

    #[DataProvider('permittedResourceTypes')]
    public function test_a_saved_view_on_a_permitted_resource_is_accepted_as_a_report_source(string $resourceType): void
    {
        $savedView = $this->makeSavedView($resourceType);
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post('/marlin/reports', [
            'name' => 'Export of '.$resourceType,
            'report_type' => ReportDefinition::TYPE_SAVED_VIEW_EXPORT,
            'saved_view_id' => $savedView->getKey(),
            'format' => 'csv',
            'schedule_frequency' => 'none',
            'recipients' => ['demo.owner@throughput.dev'],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        TenantContext::run($this->marlin, function () use ($savedView): void {
            $this->assertDatabaseHas('report_definitions', [
                'saved_view_id' => $savedView->getKey(),
                'report_type' => ReportDefinition::TYPE_SAVED_VIEW_EXPORT,
            ]);
        });
    }

    /**
     * BR-REP-01 numește explicit `contacts` printre sursele permise, iar docblock-ul din
     * `StoreReportRequest` (linia validării de SENS) confirmă aceeași intenție: „resursă pe
     * care mecanismul de export o suportă azi — accounts/contacts/orders". Testul de mai jos
     * codifică EXACT acea regulă — și pică, azi, pe cod nemodificat.
     *
     * Defect real, verificat, NEREPARAT (în afara scopului acestui lot — testare, nu cod):
     * `StoreReportRequest::withValidator()` respinge dacă
     * `! array_key_exists($resource_type, ExportableResources::map())` **SAU**
     * `! SavedViewResourceType::isSupported($resource_type)`. `ExportableResources::map()`
     * (mecanismul de export propriu-zis, `ContactList`) conține `contacts` — dar
     * `SavedViewResourceType::REGISTRY` (registrul selectorului de coloane, o construcție
     * SEPARATĂ, plan §9, „aplicat mai întâi pe Accounts/Deals") listează doar
     * `accounts/deals/orders/products` — deloc `contacts`. A doua condiție respinge deci
     * ORICE vedere salvată pe contacts, la fel ca pe deals/products, indiferent de intenția
     * documentată. Reprodus și confirmat manual (2026-09-22): POST cu o vedere `contacts`
     * întoarce eroarea de validare `saved_view_unsupported_type` — nu succesul cerut de
     * BR-REP-01. Aceeași cauză respinge `contacts` și din `eligibleSavedViews()` (vezi mai
     * jos), deci un utilizator nu poate nici măcar ALEGE o vedere de contacte din formular.
     *
     * Consecință suplimentară a aceleiași cauze: `StoreSavedViewRequest::rules()` restrânge
     * `resource_type` la `SavedViewResourceType::supported()`, deci o vedere salvată pe
     * `contacts` nici nu poate fi creată azi prin fluxul HTTP normal — testul de mai jos o
     * creează direct în bază (`forceCreate`), la fel ca `ReportSavedViewExportTest`, exact ca
     * să izoleze regula de business testată aici de acest gol separat, deja cunoscut.
     */
    public function test_a_saved_view_on_contacts_is_accepted_as_a_report_source(): void
    {
        $this->skipWhileContactsAreStillRejected();

        $savedView = $this->makeSavedView('contacts');
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post('/marlin/reports', [
            'name' => 'Export of contacts',
            'report_type' => ReportDefinition::TYPE_SAVED_VIEW_EXPORT,
            'saved_view_id' => $savedView->getKey(),
            'format' => 'csv',
            'schedule_frequency' => 'none',
            'recipients' => ['demo.owner@throughput.dev'],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        TenantContext::run($this->marlin, function () use ($savedView): void {
            $this->assertDatabaseHas('report_definitions', [
                'saved_view_id' => $savedView->getKey(),
                'report_type' => ReportDefinition::TYPE_SAVED_VIEW_EXPORT,
            ]);
        });
    }

    #[DataProvider('forbiddenResourceTypes')]
    public function test_a_saved_view_on_a_forbidden_resource_is_rejected_at_save_time(string $resourceType): void
    {
        $savedView = $this->makeSavedView($resourceType);
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)
            ->from('/marlin/reports/create')
            ->post('/marlin/reports', [
                'name' => 'Export of '.$resourceType,
                'report_type' => ReportDefinition::TYPE_SAVED_VIEW_EXPORT,
                'saved_view_id' => $savedView->getKey(),
                'format' => 'csv',
                'schedule_frequency' => 'none',
                'recipients' => ['demo.owner@throughput.dev'],
            ]);

        $response->assertRedirect('/marlin/reports/create');
        $response->assertSessionHasErrors([
            'saved_view_id' => __('rules.reports.saved_view_unsupported_type', ['type' => $resourceType]),
        ]);

        TenantContext::run($this->marlin, function () use ($savedView): void {
            $this->assertDatabaseMissing('report_definitions', ['saved_view_id' => $savedView->getKey()]);
        });
    }

    /**
     * `ReportController::eligibleSavedViews()` — vederile oferite formularului de creare
     * (`Reports/Create`) nu trebuie NICIODATĂ să conțină o vedere pe deals/products, ca
     * formularul să nu propună o alegere pe care serverul ar respinge-o oricum. Vederile
     * `accounts`/`orders`, ambele ale proprietarului, trebuie să apară.
     *
     * `contacts` NU e afirmat aici (nici prezent, nici absent) — apariția lui în această
     * listă e guvernată de EXACT ACELAȘI defect documentat mai sus
     * (`test_a_saved_view_on_contacts_is_accepted_as_a_report_source`); a repeta acea
     * afirmație într-un al doilea test ar fi doar zgomot, nu acoperire suplimentară.
     */
    public function test_eligible_saved_views_never_include_deals_or_products(): void
    {
        $this->makeSavedView('accounts', 'Active accounts');
        $this->makeSavedView('orders', 'Open orders');
        $this->makeSavedView('deals', 'Won deals');
        $this->makeSavedView('products', 'Low stock products');
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get('/marlin/reports/create');

        $response->assertOk();
        $response->assertInertia(function (Assert $page): void {
            $page->component('Reports/Create');

            $page->has('savedViews', 2);

            $page->where('savedViews', function (Collection $savedViews): bool {
                $resourceTypes = $savedViews->pluck('resourceType');

                $this->assertNotContains('deals', $resourceTypes, 'eligibleSavedViews() must never offer a deals saved view as a report source.');
                $this->assertNotContains('products', $resourceTypes, 'eligibleSavedViews() must never offer a products saved view as a report source.');
                $this->assertContains('accounts', $resourceTypes);
                $this->assertContains('orders', $resourceTypes);

                return true;
            });
        });
    }

    /**
     * Skip CONDIȚIONAT cât timp defectul descris mai sus e nereparat, pe modelul lui
     * `MemberRoleChangeTest::test_the_change_role_affordance_is_hidden_in_demo_mode()`:
     * testul se auto-activează în clipa în care codul se repară, fără ca cineva să-și
     * amintească să șteargă skip-ul. Un skip necondiționat ar fi rămas acolo tăcut și după fix.
     *
     * Condiția are DOUĂ ramuri, fiindcă defectul are două reparații posibile, iar testul
     * trebuie să se trezească la oricare dintre ele:
     *   (a) se scoate conjunctul `SavedViewResourceType::isSupported()` din cele trei locuri
     *       care îl consultă (`StoreReportRequest`, `UpdateReportRequest`,
     *       `ReportController::eligibleSavedViews()`) — varianta care urmează litera
     *       BR-REP-01, unde criteriul e exportabilitatea; `GenerateReportJob:171` rezolvă
     *       oricum lista prin `ExportableResources::resolve()`, nu prin registrul de coloane.
     *       Sub (a), referința dispare din sursa cererii.
     *   (b) se înregistrează `contacts` în `SavedViewResourceType::REGISTRY` — varianta care
     *       aduce și selectorul de coloane pe Contacts, deci o schimbare de UI, nu doar de
     *       validare. Sub (b), `isSupported('contacts')` devine `true`.
     *
     * Decizia dintre (a) și (b) e a proprietarului — de aceea lotul care a scris testul nu a
     * atins codul de producție.
     */
    private function skipWhileContactsAreStillRejected(): void
    {
        $gateStillConsultsTheColumnRegistry = str_contains(
            (string) file_get_contents(app_path('Http/Requests/Reports/StoreReportRequest.php')),
            'SavedViewResourceType::isSupported',
        );

        if ($gateStillConsultsTheColumnRegistry && ! SavedViewResourceType::isSupported('contacts')) {
            $this->markTestSkipped(
                'BR-REP-01 cere `contacts` printre sursele permise, dar validarea din '
                .'`StoreReportRequest` adaugă un al doilea criteriu — registrul selectorului de '
                .'coloane (`SavedViewResourceType::REGISTRY`), care nu conține `contacts`. '
                .'Defect confirmat, reparație nedecisă încă (vezi docblock-ul testului).',
            );
        }
    }

    private function makeSavedView(string $resourceType, ?string $name = null): SavedView
    {
        return TenantContext::run($this->marlin, fn () => SavedView::forceCreate([
            'resource_type' => $resourceType,
            'name' => $name ?? ucfirst($resourceType).' view',
            'filters' => [],
            'columns' => [],
            'sort' => 'name',
            'visibility' => SavedView::VISIBILITY_PRIVATE,
            'user_id' => $this->owner->getKey(),
        ]));
    }
}
