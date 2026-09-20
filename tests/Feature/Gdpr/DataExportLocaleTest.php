<?php

namespace Tests\Feature\Gdpr;

use App\Mail\DataExportReadyMail;
use App\Models\DataExportRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ADR-022, specs.md §15.8 FR-I18N-05 — spre deosebire de rapoarte (`recipients` arbitrari,
 * fără cont), destinatarul unui export GDPR e chiar Owner-ul autentificat care l-a cerut:
 * un cont real, cu `users.locale` propriu. `App\Jobs\Gdpr\PlanDataExportJob` îl rezolvă la
 * planificare (`requested_by`) și îl transmite mai departe lui
 * `App\Jobs\Gdpr\FinalizeDataExportJob`, ca scalar de constructor (ADR-013/014), exact ca
 * `tenantId`/`dataExportRequestId`.
 *
 * Coadă `bulk` REALĂ (nu `Bus::fake()`) — pe modelul
 * `tests/Feature/Gdpr/DataExportArchiveTest.php`: `finally()` al batch-ului dispecerizează
 * `FinalizeDataExportJob` doar când coada chiar rulează.
 */
class DataExportLocaleTest extends TestCase
{
    private Tenant $marlin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->clearDatabaseTenantContext();

        Storage::fake('local');
    }

    public function test_the_export_ready_email_is_rendered_in_the_requesters_locale_french(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['locale' => 'fr']);
        $this->makeMember($this->marlin, $owner->email, Permissions::OWNER, $owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)->post('/marlin/settings/data-export')->assertRedirect();
        $this->drainBulkQueue();

        $export = TenantContext::run($this->marlin, fn () => DataExportRequest::query()->sole());
        $this->clearDatabaseTenantContext();

        $this->assertSame(DataExportRequest::STATUS_COMPLETED, $export->status, (string) $export->error_message);

        Mail::assertSent(DataExportReadyMail::class, function (DataExportReadyMail $mail) use ($owner) {
            return $mail->hasSubject(
                trans('mail.data_export_ready.subject', ['workspace' => 'Marlin Fasteners & Supply Co.'], 'fr')
            ) && $mail->hasTo($owner->email);
        });
    }

    public function test_the_export_ready_email_falls_back_to_english_for_an_english_requester(): void
    {
        Mail::fake();

        // `User::factory()` nu forțează `locale` — implicitul de coloană e `en`.
        $owner = User::factory()->create(['locale' => 'en']);
        $this->makeMember($this->marlin, $owner->email, Permissions::OWNER, $owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)->post('/marlin/settings/data-export')->assertRedirect();
        $this->drainBulkQueue();

        Mail::assertSent(DataExportReadyMail::class, function (DataExportReadyMail $mail) use ($owner) {
            return $mail->hasSubject(
                trans('mail.data_export_ready.subject', ['workspace' => 'Marlin Fasteners & Supply Co.'], 'en')
            ) && $mail->hasTo($owner->email);
        });
    }

    /**
     * Drenează coada `bulk` până se golește — planificatorul, joburile de entitate ȘI
     * finalizarea (`finally()` al batch-ului) ajung să ruleze, ca într-un worker real.
     * Tiparul din `tests/Feature/Gdpr/DataExportArchiveTest.php`.
     */
    private function drainBulkQueue(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--queue' => 'bulk',
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);
    }
}
