<?php

namespace Tests\Feature\Gdpr;

use App\Actions\Gdpr\DataExportPaths;
use App\Jobs\Gdpr\FinalizeDataExportJob;
use App\Mail\DataExportReadyMail;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\DataExportRequest;
use App\Models\Deal;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesInvoices;
use Tests\Concerns\CreatesOrders;
use Tests\Concerns\CreatesPipelines;
use Tests\TestCase;
use ZipArchive;

/**
 * FR-GDPR-01, US-GDPR-01 (specs.md §20.5) — exportul cap-coadă: cerere HTTP → job
 * planificator → un job per entitate în `Bus::batch()` → finalizare → arhivă + email.
 *
 * Coada e `database`, nu `sync` (`.ai/rules/tenancy.md`): joburile rulează într-un worker
 * real, fără contextul cererii — singura condiție în care o greșeală de serializare a
 * contextului de tenant e vizibilă.
 */
class DataExportArchiveTest extends TestCase
{
    use CreatesInvoices, CreatesOrders, CreatesPipelines;

    private Tenant $marlin;

    private User $owner;

    private Tenant $cascade;

    private User $cascadeOwner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->cascadeOwner = $this->makeMember($this->cascade, 'demo.cascade-owner@throughput.dev', Permissions::OWNER);

        $this->seedWorkspace($this->marlin, $this->owner, 'Marlin Metals LLC');
        $this->seedWorkspace($this->cascade, $this->cascadeOwner, 'Cascade Secret Customer Inc.');

        $this->clearDatabaseTenantContext();
    }

    public function test_it_produces_an_archive_with_one_json_per_entity_a_manifest_and_csv_for_flat_tables(): void
    {
        Mail::fake();

        $this->actingAs($this->owner)->post('/marlin/settings/data-export')->assertRedirect();
        $this->drainBulkQueue();

        $export = $this->soleRequest($this->marlin);

        $this->assertSame(DataExportRequest::STATUS_COMPLETED, $export->status, (string) $export->error_message);
        $this->assertNotNull($export->file_path);
        $this->assertNotNull($export->completed_at);
        $this->assertNotNull($export->expires_at);
        $this->assertSame(7, (int) now()->startOfDay()->diffInDays($export->expires_at->startOfDay()));
        Storage::disk('local')->assertExists($export->file_path);

        $names = $this->entryNames($export->file_path);

        // Un JSON per entitate + manifest (FR-GDPR-01).
        $this->assertEqualsCanonicalizing([
            'manifest.json',
            'accounts.json',
            'contacts.json', 'contacts.csv',
            'deals.json', 'deals.csv',
            'orders.json', 'orders.csv',
            'invoices.json', 'invoices.csv',
            'payments.json', 'payments.csv',
            'activity_log.json',
        ], $names);

        // „Niciun PDF ca payload principal" (plan §11) — aici, niciun PDF deloc.
        $this->assertSame([], array_filter($names, fn (string $name): bool => str_ends_with($name, '.pdf')));
    }

    public function test_the_archive_contains_only_the_current_workspace(): void
    {
        $this->actingAs($this->owner)->post('/marlin/settings/data-export')->assertRedirect();
        $this->drainBulkQueue();

        $export = $this->soleRequest($this->marlin);

        /** @var list<array<string, mixed>> $accounts */
        $accounts = json_decode($this->entry($export->file_path, 'accounts.json'), true);

        $this->assertCount(1, $accounts);
        $this->assertSame('Marlin Metals LLC', $accounts[0]['name']);
        $this->assertSame($this->marlin->getKey(), $accounts[0]['tenant_id']);

        // Izolarea vine din global scope + RLS, nu dintr-un `where` scris în export.
        $this->assertStringNotContainsString('Cascade Secret Customer Inc.', $this->entry($export->file_path, 'accounts.json'));
    }

    public function test_orders_keep_their_lines_nested_in_json_while_the_csv_stays_flat(): void
    {
        $this->actingAs($this->owner)->post('/marlin/settings/data-export')->assertRedirect();
        $this->drainBulkQueue();

        $export = $this->soleRequest($this->marlin);

        /** @var list<array<string, mixed>> $orders */
        $orders = json_decode($this->entry($export->file_path, 'orders.json'), true);

        $this->assertCount(1, $orders);
        $this->assertArrayHasKey('order_lines', $orders[0]);
        $this->assertCount(2, $orders[0]['order_lines']);

        $csv = array_values(array_filter(explode("\n", trim($this->entry($export->file_path, 'orders.csv')))));

        $this->assertCount(2, $csv, 'CSV-ul de comenzi are antet + un rând per COMANDĂ, nu per linie.');
        $this->assertStringContainsString('order_number', $csv[0]);
    }

    public function test_the_manifest_describes_every_entity_and_what_is_not_included(): void
    {
        $this->actingAs($this->owner)->post('/marlin/settings/data-export')->assertRedirect();
        $this->drainBulkQueue();

        $export = $this->soleRequest($this->marlin);

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->entry($export->file_path, 'manifest.json'), true);

        $this->assertSame('Marlin Fasteners & Supply Co.', $manifest['workspace']['name']);
        $this->assertSame($this->owner->name, $manifest['request']['requestedBy']['name']);
        $this->assertSame(7, $manifest['totals']['entities']);
        $this->assertNotEmpty($manifest['notIncluded']);

        $byName = collect($manifest['entities'])->keyBy('name');

        $this->assertEqualsCanonicalizing(
            ['accounts', 'contacts', 'deals', 'orders', 'invoices', 'payments', 'activity_log'],
            $byName->keys()->all(),
        );
        $this->assertSame(1, $byName['accounts']['rows']);
        $this->assertSame(['orders.json', 'orders.csv'], $byName['orders']['files']);
        $this->assertSame(['accounts.json'], $byName['accounts']['files']);
        $this->assertSame(['order_lines'], $byName['orders']['nested']);
        $this->assertContains('name', $byName['accounts']['fields']);
    }

    public function test_it_emails_the_requester_a_download_link_and_cleans_up_the_work_folder(): void
    {
        Mail::fake();

        $this->actingAs($this->owner)->post('/marlin/settings/data-export')->assertRedirect();
        $this->drainBulkQueue();

        $export = $this->soleRequest($this->marlin);

        Mail::assertSent(DataExportReadyMail::class, function (DataExportReadyMail $mail) use ($export): bool {
            return $mail->hasTo($this->owner->email)
                && str_contains($mail->downloadUrl, "/marlin/settings/data-export/{$export->getKey()}/download");
        });

        Storage::disk('local')->assertMissing(
            DataExportPaths::workFolder($this->marlin->getKey(), $export->getKey()).'/accounts.json'
        );
    }

    public function test_the_author_can_download_the_archive_and_another_owner_cannot(): void
    {
        $secondOwner = $this->makeMember($this->marlin, 'demo.second-owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post('/marlin/settings/data-export')->assertRedirect();
        $this->drainBulkQueue();

        $export = $this->soleRequest($this->marlin);
        $url = "/marlin/settings/data-export/{$export->getKey()}/download";

        $response = $this->actingAs($this->owner)->get($url);
        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('marlin-data-export-', $response->headers->get('content-disposition'));

        $this->actingAs($secondOwner)->get($url)->assertForbidden();
    }

    public function test_an_owner_of_another_workspace_gets_a_404_not_a_403(): void
    {
        $this->actingAs($this->owner)->post('/marlin/settings/data-export')->assertRedirect();
        $this->drainBulkQueue();

        $export = $this->soleRequest($this->marlin);

        // RLS + global scope: rândul nu EXISTĂ pentru celălalt tenant, deci legarea de rută
        // eșuează înainte de Policy (§18.5, același principiu ca pe API).
        $this->actingAs($this->cascadeOwner)
            ->get("/cascade/settings/data-export/{$export->getKey()}/download")
            ->assertNotFound();
    }

    /**
     * Două decizii de conținut, amândouă din §20.5, verificate împreună fiindcă merg în
     * direcții opuse și e ușor de crezut că una o contrazice pe cealaltă:
     *
     *  - un deal ȘTERS e în continuare un rând PĂSTRAT, deci intră în răspunsul la o cerere
     *    de acces/portabilitate, cu `deleted_at` la vedere;
     *  - un contact ANONIMIZAT (Art. 17, BR-CRM-01) NU intră: identitatea a fost deja
     *    ștearsă, iar rândul rămas nu mai conține date personale de predat. Specificația
     *    cere explicit ca el să dispară „din listă, căutare, export".
     */
    public function test_deleted_deals_are_included_and_anonymised_contacts_are_not(): void
    {
        TenantContext::run($this->marlin, function (): void {
            Deal::query()->firstOrFail()->delete();

            $contact = new Contact([
                'account_id' => Account::query()->value('id'),
                'first_name' => 'Anonymized',
                'last_name' => 'contact',
            ]);
            $contact->created_by = $this->owner->getKey();
            // `anonymized_at` nu e `#[Fillable]` deliberat (vezi `Contact`): se scrie o
            // singură dată, din acțiunea de ștergere.
            $contact->anonymized_at = now();
            $contact->save();
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post('/marlin/settings/data-export')->assertRedirect();
        $this->drainBulkQueue();

        $export = $this->soleRequest($this->marlin);

        /** @var list<array<string, mixed>> $deals */
        $deals = json_decode($this->entry($export->file_path, 'deals.json'), true);
        $this->assertCount(1, $deals);
        $this->assertNotNull($deals[0]['deleted_at']);

        /** @var list<array<string, mixed>> $contacts */
        $contacts = json_decode($this->entry($export->file_path, 'contacts.json'), true);
        $this->assertCount(1, $contacts);
        $this->assertSame('Dana', $contacts[0]['first_name']);
    }

    /**
     * Spre deosebire de o operație în masă (BR-BULK-01, `allowFailures()`), un export
     * GDPR incomplet NU se livrează: o arhivă fără `invoices.json` ar fi un răspuns greșit
     * la o cerere de portabilitate, nu unul parțial.
     */
    public function test_a_missing_entity_file_fails_the_export_instead_of_delivering_a_partial_archive(): void
    {
        $export = TenantContext::run($this->marlin, function (): DataExportRequest {
            $export = new DataExportRequest([
                'status' => DataExportRequest::STATUS_PROCESSING,
                'requested_at' => now(),
            ]);
            $export->requested_by = $this->owner->getKey();
            $export->save();

            return $export;
        });
        $this->clearDatabaseTenantContext();

        // Nicio parte scrisă pe disc — exact situația în care un job de entitate a căzut.
        (new FinalizeDataExportJob($this->marlin->getKey(), $export->getKey()))->handle();

        $fresh = $this->soleRequest($this->marlin);

        $this->assertSame(DataExportRequest::STATUS_FAILED, $fresh->status);
        $this->assertNull($fresh->file_path);
        $this->assertNotNull($fresh->error_message);
        Storage::disk('local')->assertMissing(
            DataExportPaths::archive($this->marlin->getKey(), $export->getKey())
        );
    }

    /**
     * Fixtură care atinge toate cele șapte entități, ca arhiva să nu treacă verde pe tabele
     * goale.
     */
    private function seedWorkspace(Tenant $tenant, User $owner, string $accountName): void
    {
        TenantContext::run($tenant, function () use ($tenant, $owner, $accountName): void {
            $account = new Account([
                'name' => $accountName,
                'status' => Account::STATUS_ACTIVE,
                'owner_user_id' => $owner->getKey(),
                'billing_address' => ['line1' => '1 Industrial Way', 'city' => 'Akron'],
                'tags' => ['wholesale'],
            ]);
            $account->created_by = $owner->getKey();
            $account->save();

            $contact = new Contact([
                'account_id' => $account->getKey(),
                'first_name' => 'Dana',
                'last_name' => 'Reyes',
                'email' => 'dana.reyes@example.test',
                'is_primary' => true,
            ]);
            $contact->created_by = $owner->getKey();
            $contact->save();

            $pipeline = $this->makeDefaultPipeline($tenant);

            $deal = new Deal([
                'account_id' => $account->getKey(),
                'primary_contact_id' => $contact->getKey(),
                'pipeline_id' => $pipeline['pipeline']->getKey(),
                'stage_id' => $pipeline['stages']['New']->getKey(),
                'owner_user_id' => $owner->getKey(),
                'title' => 'Annual supply agreement',
                'value' => 4200.00,
                'currency' => 'USD',
                'status' => Deal::STATUS_OPEN,
            ]);
            $deal->created_by = $owner->getKey();
            $deal->save();

            $variant = $this->makeVariant();
            $order = $this->confirmedOrder($account, $owner, 500.0);

            foreach ([['HEX-BOLT-M8 box', 10, 25.0], ['Shipping crate', 1, 250.0]] as [$description, $quantity, $price]) {
                OrderLine::query()->create([
                    'order_id' => $order->getKey(),
                    'variant_id' => $variant->getKey(),
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'discount' => 0,
                    'line_total' => $quantity * $price,
                    'quantity_fulfilled' => 0,
                ]);
            }

            $invoice = $this->sentInvoice($order, 500.0, 200.0);

            $payment = new Payment([
                'invoice_id' => $invoice->getKey(),
                'amount' => 200.0,
                'method' => Payment::METHOD_BANK_TRANSFER,
                'paid_at' => now(),
            ]);
            $payment->created_by = $owner->getKey();
            $payment->save();

            ActivityLog::query()->create([
                'user_id' => $owner->getKey(),
                'action' => 'created',
                'auditable_type' => Order::class,
                'auditable_id' => $order->getKey(),
                'old_values' => null,
                'new_values' => ['status' => 'confirmed'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
            ]);
        });

        $this->clearDatabaseTenantContext();
    }

    /**
     * @return list<string>
     */
    private function entryNames(string $archivePath): array
    {
        $zip = $this->openArchive($archivePath);
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        return $names;
    }

    private function entry(string $archivePath, string $name): string
    {
        $zip = $this->openArchive($archivePath);
        $contents = $zip->getFromName($name);
        $zip->close();

        $this->assertIsString($contents, "Arhiva nu conține [{$name}].");

        return $contents;
    }

    private function openArchive(string $archivePath): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue(
            $zip->open(Storage::disk('local')->path($archivePath)) === true,
            'Arhiva nu s-a putut deschide.',
        );

        return $zip;
    }

    private function soleRequest(Tenant $tenant): DataExportRequest
    {
        $export = TenantContext::run($tenant, fn () => DataExportRequest::query()->sole());
        $this->clearDatabaseTenantContext();

        return $export;
    }

    /**
     * Drenează coada `bulk` până se golește — planificatorul, joburile de entitate ȘI
     * finalizarea (`finally()` al batch-ului) ajung să ruleze, ca într-un worker real.
     * Tiparul din `tests/Feature/Bulk/ReassignOwnerTest.php`.
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
