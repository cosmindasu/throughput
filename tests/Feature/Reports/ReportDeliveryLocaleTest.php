<?php

namespace Tests\Feature\Reports;

use App\Jobs\Reports\DeliverReportJob;
use App\Jobs\Reports\GenerateReportJob;
use App\Mail\ReportDeliveryMail;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ADR-022, specs.md §15.8 FR-I18N-05 — un raport livrat pe email se randează în limba
 * CREATORULUI definiției (`report_definitions.created_by`), nu a implicitului cererii care
 * a declanșat rularea: `recipients` sunt adrese arbitrare, fără cont, deci fără propriul
 * `users.locale` — vezi docblock-ul `App\Jobs\Reports\GenerateReportJob::handle()`, unde
 * se face aproximarea, în oglindă cu „cine e «me»" deja stabilit pentru un filtru salvat.
 *
 * Coadă REALĂ (`database`, nu `sync`/`Queue::fake()`) pentru testul de capăt-la-capăt de
 * mai jos, pe modelul `.ai/rules/tenancy.md` — un job rulat cu `sync` ar vedea contextul
 * cererii de test încă viu.
 */
class ReportDeliveryLocaleTest extends TestCase
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

    private function makeReport(User $creator): ReportDefinition
    {
        return TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_INVENTORY_VALUATION,
            'name' => 'Inventory Valuation',
            'format' => ReportDefinition::FORMAT_CSV,
            'schedule_frequency' => ReportDefinition::FREQUENCY_NONE,
            // Adresă arbitrară, fără cont — exact cazul pentru care FR-I18N-05 cere
            // aproximarea pe creator, nu pe destinatar.
            'recipients' => ['external-accountant@example.test'],
            'is_active' => true,
            'created_by' => $creator->getKey(),
        ]));
    }

    private function makeQueuedRun(ReportDefinition $report): ReportRun
    {
        return TenantContext::run($this->marlin, fn () => ReportRun::query()->create([
            'report_definition_id' => $report->getKey(),
            'status' => ReportRun::STATUS_QUEUED,
            'triggered_by' => ReportRun::TRIGGERED_BY_MANUAL,
        ]));
    }

    /**
     * Testul de „sudură" — verifică EXACT punctul unde ADR-022 cere rezolvarea
     * (`App\Jobs\Reports\GenerateReportJob`, imediat înainte de dispecerizare), fără să
     * treacă prin randarea efectivă a email-ului.
     */
    public function test_generate_report_job_resolves_the_creators_locale_and_passes_it_to_delivery(): void
    {
        Bus::fake([DeliverReportJob::class]);

        $creator = $this->makeCreator('fr');
        $report = $this->makeReport($creator);
        $run = $this->makeQueuedRun($report);

        (new GenerateReportJob($this->marlin->getKey(), $run->getKey()))->handle();

        Bus::assertDispatched(DeliverReportJob::class, fn (DeliverReportJob $job) => $job->locale === 'fr');
    }

    public function test_the_delivery_email_is_rendered_in_french_for_a_french_creator(): void
    {
        Mail::fake();

        $creator = $this->makeCreator('fr');
        $report = $this->makeReport($creator);
        $run = $this->makeQueuedRun($report);

        // Lanțul REAL — `GenerateReportJob` dispecerizează `DeliverReportJob` cu locale-ul
        // calculat de el, nu unul scris de mână în test.
        (new GenerateReportJob($this->marlin->getKey(), $run->getKey()))->handle();

        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'default', '--stop-when-empty' => true, '--no-interaction' => true]);

        Mail::assertSent(ReportDeliveryMail::class, function (ReportDeliveryMail $mail) {
            return $mail->hasSubject(trans('mail.report_delivery.subject', ['report' => 'Inventory Valuation'], 'fr'))
                && $mail->hasTo('external-accountant@example.test');
        });
    }

    public function test_the_delivery_email_falls_back_to_english_for_an_english_creator(): void
    {
        Mail::fake();

        // `User::factory()` nu forțează `locale` — implicitul de coloană e `en`
        // (migrația `2026_09_20_110000_add_locale_to_users_table`), fără cod aplicativ.
        $creator = $this->makeCreator('en');
        $report = $this->makeReport($creator);
        $run = $this->makeQueuedRun($report);

        (new GenerateReportJob($this->marlin->getKey(), $run->getKey()))->handle();

        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'default', '--stop-when-empty' => true, '--no-interaction' => true]);

        Mail::assertSent(ReportDeliveryMail::class, function (ReportDeliveryMail $mail) {
            return $mail->hasSubject(trans('mail.report_delivery.subject', ['report' => 'Inventory Valuation'], 'en'))
                && $mail->hasTo('external-accountant@example.test');
        });
    }
}
