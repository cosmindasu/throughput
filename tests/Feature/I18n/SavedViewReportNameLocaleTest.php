<?php

namespace Tests\Feature\I18n;

use App\Models\ReportDefinition;
use App\Models\SavedView;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * ADR-022, specs.md §15.8 FR-I18N-06 — granița traducere/conținut, pe cele DOUĂ resurse
 * numite explicit de cerință: numele unei vederi salvate (`saved_views.name`) și numele
 * unei definiții de raport (`report_definitions.name`). Conținut scris de UTILIZATOR, nu
 * generat de aplicație — rămâne neschimbat, caracter cu caracter, la comutarea limbii
 * interfeței.
 *
 * Verifică PROPS-ul Inertia (`assertInertia`/`AssertableInertia`), nu HTML-ul — pe modelul
 * `ActivityLocaleTest`, care acoperă deja aceeași graniță pentru titlul unui deal în fraza de
 * activitate. Testul de față e complementar, nu duplicat: `ActivityLocaleTest` nu atinge
 * deloc ecranele `Reports/*`, iar `saved_views.name`/`report_definitions.name` sunt cele
 * două resurse numite explicit de FR-I18N-06 (§15.1, §16.1) — nicăieri altundeva în suită.
 *
 * Lanțul REAL de middleware (`SetLocale`), `users.locale` pe utilizator — niciodată
 * `App::setLocale()` chemat direct în test, ca la `FlashMessageLocaleTest`/`ActivityLocaleTest`.
 *
 * Două valori adversariale, ambele cerute explicit de task:
 *  - o frază englezească tentantă de „tradus" din greșeală ("Open deals this quarter"),
 *    pusă pe NUMELE vederii salvate;
 *  - o valoare care se potrivește EXACT cu o cheie reală din catalogul de traduceri Laravel
 *    (`flash.reports.created`, care înseamnă „Report created." în engleză și „Rapport créé."
 *    în franceză — text DIFERIT pe cele două limbi, verificat în `lang/{en,fr}/flash.php`),
 *    pusă pe NUMELE raportului. Dacă vreun cod ar face vreodată `__($report->name)` din
 *    greșeală în loc să trateze numele ca text opac, valoarea AR schimba forma la comutarea
 *    de limbă — exact ce prinde acest test, spre deosebire de o frază oarecare, care ar
 *    rămâne identică din întâmplare chiar și printr-un `__()` greșit (cheie inexistentă →
 *    Laravel întoarce cheia primită neschimbată, vezi `MissingKeyFallbackTest`).
 */
class SavedViewReportNameLocaleTest extends TestCase
{
    private const TEMPTING_SAVED_VIEW_NAME = 'Open deals this quarter';

    /**
     * Coincide EXACT cu cheia `flash.reports.created` din `lang/{en,fr}/flash.php` —
     * "Report created." (en) / "Rapport créé." (fr). Ales deliberat: dacă `__()` ar fi
     * aplicat din greșeală peste `report_definitions.name`, valoarea CHIAR s-ar traduce.
     */
    private const TRAP_REPORT_NAME = 'flash.reports.created';

    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();
    }

    public function test_saved_view_and_report_names_stay_identical_across_locale_switch_on_reports_index(): void
    {
        [$savedView, $report] = $this->seedSavedViewExportReport();

        // Pasul 1 — utilizator implicit (`en`).
        $this->actingAs($this->owner)->get('/marlin/reports')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Index')
                ->where('reports.0.name', self::TRAP_REPORT_NAME)
                ->where('reports.0.sourceLabel', self::TEMPTING_SAVED_VIEW_NAME)
                ->where('reports.0.savedView.name', self::TEMPTING_SAVED_VIEW_NAME));

        // Pasul 2 — ACELAȘI raport/vedere, DAR utilizatorul comută interfața pe franceză.
        // Verificarea „bit cu bit" cerută de task: dacă vreo valoare de mai sus s-ar schimba
        // aici, ar însemna că mecanismul de traducere a atins conținut scris de utilizator.
        $this->owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/reports')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Index')
                ->where('reports.0.name', self::TRAP_REPORT_NAME)
                ->where('reports.0.sourceLabel', self::TEMPTING_SAVED_VIEW_NAME)
                ->where('reports.0.savedView.name', self::TEMPTING_SAVED_VIEW_NAME));

        $this->assertSame(self::TEMPTING_SAVED_VIEW_NAME, $savedView->fresh()->name);
        $this->assertSame(self::TRAP_REPORT_NAME, $report->fresh()->name);
    }

    /**
     * Aceeași graniță, pe ecranul de DETALIU (`Reports/Show`) — prop separat de
     * `Reports/Index`, deci o eventuală traducere greșită introdusă doar în `show()` n-ar fi
     * prinsă de testul de mai sus.
     */
    public function test_saved_view_and_report_names_stay_identical_across_locale_switch_on_reports_show(): void
    {
        [, $report] = $this->seedSavedViewExportReport();

        $this->actingAs($this->owner)->get("/marlin/reports/{$report->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Show')
                ->where('report.name', self::TRAP_REPORT_NAME)
                ->where('report.savedView.name', self::TEMPTING_SAVED_VIEW_NAME));

        $this->owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/reports/{$report->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Show')
                ->where('report.name', self::TRAP_REPORT_NAME)
                ->where('report.savedView.name', self::TEMPTING_SAVED_VIEW_NAME));
    }

    /**
     * @return array{0: SavedView, 1: ReportDefinition}
     */
    private function seedSavedViewExportReport(): array
    {
        $result = TenantContext::run($this->marlin, function (): array {
            $savedView = new SavedView([
                'resource_type' => 'accounts',
                'name' => self::TEMPTING_SAVED_VIEW_NAME,
                'filters' => [],
                'columns' => [],
                'sort' => ['field' => 'createdAt', 'direction' => 'desc'],
                'visibility' => SavedView::VISIBILITY_PRIVATE,
            ]);
            $savedView->user_id = $this->owner->getKey();
            $savedView->save();

            $report = ReportDefinition::forceCreate([
                'saved_view_id' => $savedView->getKey(),
                'report_type' => ReportDefinition::TYPE_SAVED_VIEW_EXPORT,
                'name' => self::TRAP_REPORT_NAME,
                'format' => ReportDefinition::FORMAT_CSV,
                'schedule_frequency' => ReportDefinition::FREQUENCY_NONE,
                'recipients' => [$this->owner->email],
                'is_active' => true,
                'created_by' => $this->owner->getKey(),
            ]);

            return [$savedView, $report];
        });

        $this->clearDatabaseTenantContext();

        return $result;
    }
}
