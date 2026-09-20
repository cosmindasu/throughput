<?php

namespace Tests\Feature\I18n;

use App\Jobs\Reports\DeliverReportJob;
use App\Jobs\Reports\GenerateReportJob;
use App\Mail\DataExportReadyMail;
use App\Mail\ReportDeliveryMail;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ADR-022, specs.md §15.8 FR-I18N-05, `.ai/rules/tenancy.md:123-138` — garda anti-scurgere
 * pentru limbă pe un worker de coadă de viață lungă.
 *
 * Motivul concret, citat din ADR-022: „worker-ul e un proces de viață lungă;
 * `App::setLocale()` scrie pe singleton-ul `Translator` din container, iar Laravel
 * resetează între joburi doar instanțele `scoped()` — un job FR urmat de un job EN, pe
 * același worker, scurge limba primului către al doilea." E ACEEAȘI clasă de bug deja
 * găsită o dată pe `scoped()`/`bound()` fără re-legare necondiționată
 * (`.ai/rules/tenancy.md:123-138`, eticheta „(deactivated)", FR-TEN-04), aplicată acum
 * limbii în loc de tenant — de asta fixul e identic: re-legare NECONDIȚIONATĂ
 * (`App::setLocale($this->locale)`, necondiționat, la ÎNCEPUTUL fiecărui `handle()`), nu
 * „doar dacă diferă de ce e setat deja".
 *
 * Acest test există EXACT ca să transforme afirmația de mai sus într-o garanție: dacă
 * cineva scoate `App::setLocale($this->locale)` din
 * `App\Jobs\Reports\DeliverReportJob::handle()` sau din
 * `App\Jobs\Gdpr\FinalizeDataExportJob::handle()`, testul de mai jos pică — nu doar
 * teoretic, măsurat.
 */
class JobLocaleLeakTest extends TestCase
{
    private Tenant $marlin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->clearDatabaseTenantContext();

        Storage::fake('local');
    }

    private function makeCreator(string $locale): User
    {
        $user = User::factory()->create(['locale' => $locale]);
        $this->makeMember($this->marlin, $user->email, Permissions::OWNER, $user);
        $this->clearDatabaseTenantContext();

        return $user;
    }

    private function makeReport(User $creator, string $recipientEmail): ReportDefinition
    {
        return TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_INVENTORY_VALUATION,
            'name' => 'Inventory Valuation',
            'format' => ReportDefinition::FORMAT_CSV,
            'schedule_frequency' => ReportDefinition::FREQUENCY_NONE,
            'recipients' => [$recipientEmail],
            'is_active' => true,
            'created_by' => $creator->getKey(),
        ]));
    }

    /**
     * Rulează `GenerateReportJob` REAL (nu un fixture scris de mână) — produce un
     * `report_runs.status = success` cu fișier pe disc, ȘI dispecerizează pe coadă
     * `DeliverReportJob` cu locale-ul REZOLVAT de producție (nu unul scris de test).
     */
    private function makeSuccessfulRun(ReportDefinition $report): ReportRun
    {
        $run = TenantContext::run($this->marlin, fn () => ReportRun::query()->create([
            'report_definition_id' => $report->getKey(),
            'status' => ReportRun::STATUS_QUEUED,
            'triggered_by' => ReportRun::TRIGGERED_BY_MANUAL,
        ]));

        (new GenerateReportJob($this->marlin->getKey(), $run->getKey()))->handle();

        $fresh = TenantContext::run($this->marlin, fn () => ReportRun::query()->find($run->getKey()));
        $this->clearDatabaseTenantContext();

        $this->assertSame(ReportRun::STATUS_SUCCESS, $fresh->status, (string) $fresh->error_message);

        return $fresh;
    }

    /**
     * Testul PRINCIPAL — determinist, fără coadă reală (motivul e explicat mai jos),
     * exact scenariul din task: DOUĂ joburi consecutive, PE ACELAȘI proces PHP, cu locale
     * diferite.
     *
     * De ce `->handle()` direct, nu `queue:work`, e SUFICIENT aici (spre deosebire de
     * `tests/Feature/Tenancy/QueuedJobContextTest.php`, care are nevoie de coada reală):
     * acolo bug-ul ținea de `forgetScopedInstances()`, apelat de Laravel DOAR între joburi
     * reale de coadă — un artefact al mecanismului de coadă. Aici bug-ul ține de
     * `App::setLocale()`, care scrie pe ACELAȘI container, INDIFERENT dacă `handle()` e
     * apelat direct sau de `queue:work` — testul de mai jos EXPUNE mecanismul exact,
     * fără zgomotul suplimentar al unei drenări reale. Testul-pereche de mai jos
     * (`test_a_report_job_and_a_gdpr_job_share_a_worker_without_leaking_locale`) verifică
     * și varianta cu coadă reală, cu DOUĂ tipuri diferite de job.
     */
    public function test_two_consecutive_jobs_with_different_locales_do_not_leak_into_each_other(): void
    {
        Mail::fake();

        $frCreator = $this->makeCreator('fr');
        $frReport = $this->makeReport($frCreator, 'external-fr@example.test');
        $frRun = $this->makeSuccessfulRun($frReport);

        $enCreator = $this->makeCreator('en');
        $enReport = $this->makeReport($enCreator, 'external-en@example.test');
        $enRun = $this->makeSuccessfulRun($enReport);

        // Primul job — franceză.
        (new DeliverReportJob($this->marlin->getKey(), $frRun->getKey(), 'fr'))->handle();
        $this->assertSame('fr', App::getLocale(), 'Primul job trebuia să lase worker-ul pe franceză.');

        // Al doilea job — engleză, PE ACELAȘI proces. Fără `App::setLocale($this->locale)`
        // necondiționat la începutul lui `DeliverReportJob::handle()`, worker-ul ar rămâne
        // pe franceza lăsată de primul job, iar linia de mai jos ar pica.
        (new DeliverReportJob($this->marlin->getKey(), $enRun->getKey(), 'en'))->handle();
        $this->assertSame(
            'en',
            App::getLocale(),
            'Scurgere de limbă între joburi — al doilea job a moștenit franceza primului. '
            .'Vezi App\Jobs\Reports\DeliverReportJob::handle(): App::setLocale($this->locale) '
            .'trebuie să ruleze necondiționat, la începutul metodei.',
        );

        // Nu doar starea internă — și emailurile CHIAR trimise sunt în limba corectă.
        Mail::assertSent(ReportDeliveryMail::class, fn (ReportDeliveryMail $mail) => $mail->hasTo('external-fr@example.test')
            && $mail->hasSubject(trans('mail.report_delivery.subject', ['report' => 'Inventory Valuation'], 'fr')));

        Mail::assertSent(ReportDeliveryMail::class, fn (ReportDeliveryMail $mail) => $mail->hasTo('external-en@example.test')
            && $mail->hasSubject(trans('mail.report_delivery.subject', ['report' => 'Inventory Valuation'], 'en')));
    }

    /**
     * Varianta cu coadă REALĂ (`database`, `queue:work`) și DOUĂ TIPURI diferite de job —
     * `App\Jobs\Reports\DeliverReportJob` (coada `default`) și
     * `App\Jobs\Gdpr\FinalizeDataExportJob` (coada `bulk`), pe același worker
     * (`--queue=default,bulk`) — cel mai apropiat de scenariul de producție descris în
     * ADR-022: „worker-ul rulează joburi pentru [cereri] diferite", nu doar rulări repetate
     * ale ACELUIAȘI job.
     */
    public function test_a_report_job_and_a_gdpr_job_share_a_worker_without_leaking_locale(): void
    {
        Mail::fake();

        $frCreator = $this->makeCreator('fr');
        $frReport = $this->makeReport($frCreator, 'external-fr@example.test');
        // Lasă `DeliverReportJob(locale: 'fr')` în coada `default`, dispecerizat de
        // `GenerateReportJob` — nedrenat încă.
        $this->makeSuccessfulRun($frReport);

        $enOwner = User::factory()->create(['locale' => 'en']);
        $this->makeMember($this->marlin, $enOwner->email, Permissions::OWNER, $enOwner);
        $this->clearDatabaseTenantContext();

        // Lasă `App\Jobs\Gdpr\PlanDataExportJob` în coada `bulk`, care va dispecerizeze la
        // rândul lui `FinalizeDataExportJob(locale: 'en')` — nedrenat încă.
        $this->actingAs($enOwner)->post('/marlin/settings/data-export')->assertRedirect();

        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', [
            '--queue' => 'default,bulk',
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);

        Mail::assertSent(ReportDeliveryMail::class, fn (ReportDeliveryMail $mail) => $mail->hasTo('external-fr@example.test')
            && $mail->hasSubject(trans('mail.report_delivery.subject', ['report' => 'Inventory Valuation'], 'fr')));

        Mail::assertSent(DataExportReadyMail::class, fn (DataExportReadyMail $mail) => $mail->hasTo($enOwner->email)
            && $mail->hasSubject(trans('mail.data_export_ready.subject', ['workspace' => 'Marlin Fasteners & Supply Co.'], 'en')));
    }
}
