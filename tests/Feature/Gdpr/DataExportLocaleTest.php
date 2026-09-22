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
use ZipArchive;

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
     * FR-I18N-04, Lotul I18N Val 5 (a treia trecere) — `App\Actions\Gdpr\DataExportSources`
     * `label`/`note` treceau prin `lang/gdpr.php` abia acum. Verifică le manifestul RĂU din
     * arhiva chiar descărcată, nu un literal scris de test — o comparație catalog-cu-catalog
     * ar trece verde chiar dacă `ExportTenantEntityJob` n-ar evalua deloc `__()` sub locale-ul
     * corect (vezi `tests/Feature/I18n/JobLocaleLeakTest.php` pentru garda asupra jobului).
     */
    public function test_the_manifest_entity_labels_and_notes_translate_to_french_for_a_french_requester(): void
    {
        $owner = User::factory()->create(['locale' => 'fr']);
        $this->makeMember($this->marlin, $owner->email, Permissions::OWNER, $owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)->post('/marlin/settings/data-export')->assertRedirect();
        $this->drainBulkQueue();

        $manifest = $this->manifestFor($this->soleRequestFor($owner));
        $byName = collect($manifest['entities'])->keyBy('name');

        $this->assertSame('Comptes', $byName['accounts']['label']);
        $this->assertSame('Contacts', $byName['contacts']['label']);
        $this->assertSame('Affaires', $byName['deals']['label']);
        $this->assertSame('Commandes', $byName['orders']['label']);
        $this->assertSame('Factures', $byName['invoices']['label']);
        $this->assertSame('Paiements', $byName['payments']['label']);
        $this->assertSame('Journal d’activité', $byName['activity_log']['label']);

        $this->assertSame(
            'Chaque compte de cet espace de travail. L’adresse de facturation, l’adresse de livraison et les étiquettes sont des objets structurés, c’est pourquoi cette entité est uniquement au format JSON — une colonne de tableur les aurait aplaties en texte.',
            $byName['accounts']['note'],
        );
    }

    public function test_the_manifest_entity_labels_and_notes_stay_english_by_default(): void
    {
        // `User::factory()` nu forțează `locale` — implicitul de coloană e `en`.
        $owner = User::factory()->create(['locale' => 'en']);
        $this->makeMember($this->marlin, $owner->email, Permissions::OWNER, $owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)->post('/marlin/settings/data-export')->assertRedirect();
        $this->drainBulkQueue();

        $manifest = $this->manifestFor($this->soleRequestFor($owner));
        $byName = collect($manifest['entities'])->keyBy('name');

        $this->assertSame('Accounts', $byName['accounts']['label']);
        $this->assertSame('Activity log', $byName['activity_log']['label']);
        $this->assertSame(
            'Every company record in this workspace. Billing address, shipping address and tags are structured objects, which is why this entity is JSON only — a spreadsheet column would have flattened them into text.',
            $byName['accounts']['note'],
        );
    }

    private function soleRequestFor(User $owner): DataExportRequest
    {
        $export = TenantContext::run($this->marlin, fn () => DataExportRequest::query()->where('requested_by', $owner->getKey())->sole());
        $this->clearDatabaseTenantContext();

        return $export;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestFor(DataExportRequest $export): array
    {
        $zip = new ZipArchive;
        $this->assertTrue(
            $zip->open(Storage::disk('local')->path($export->file_path)) === true,
            'Arhiva nu s-a putut deschide.',
        );

        $contents = $zip->getFromName('manifest.json');
        $zip->close();

        $this->assertIsString($contents, 'Arhiva nu conține manifest.json.');

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($contents, true);

        return $manifest;
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
