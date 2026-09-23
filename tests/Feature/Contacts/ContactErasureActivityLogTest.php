<?php

namespace Tests\Feature\Contacts;

use App\Jobs\System\MaskErasedContactActivityLogJob;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Contacts\ContactErasure;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GDPR-02 (audit 2026-09-23, `docs/reviews/2026-09-23_audit/08-gdpr.md`) — proba
 * END-TO-END a fix-ului: `App\Support\Contacts\ContactErasure::erase()` nu mai lasă în
 * urmă PII în `activity_log`, pe NICIUNA din cele două ramuri (anonimizare cu deal/order
 * asociat, ștergere fizică fără referințe), nici pentru rândurile ISTORICE (create/
 * update-uri anterioare erasure-ului), nici pentru rândul scris ASINCRON de erasure-ul
 * însuși (mascat la sursă, în `App\Observers\ActivityLogObserver` — vezi docblock-ul lui
 * pentru cursa asincronă și alternativa aleasă).
 *
 * Coada e `database` (`phpunit.xml`), drenată explicit cu `queue:work --stop-when-empty`,
 * exact ca în `Tests\Feature\Activity\ActivityLogObserverTest` (plafonul de memorie al
 * workerului e dezactivat global, în `Tests\TestCase::setUp()`).
 *
 * Doi tenanți — `marlin` (contactul erasat + un alt contact, MARTOR, din același tenant)
 * și `globex` (contact MARTOR dintr-un alt tenant) — ca să dovedească exact cele două
 * excluderi cerute: alt contact din același tenant nu e atins, un contact din alt tenant
 * nu e atins. Căutarea de PII rulează sub contextul FIECĂRUI tenant (RLS filtrează altfel
 * rândurile), nu printr-o conexiune cu bypass.
 */
class ContactErasureActivityLogTest extends TestCase
{
    private Tenant $marlin;

    private Tenant $globex;

    private User $marlinOwner;

    private User $globexOwner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin-erasure-log', 'Marlin Fasteners & Supply Co.');
        $this->marlinOwner = $this->makeMember($this->marlin, 'owner@marlin-erasure-log.throughput.dev', Permissions::OWNER);

        $this->globex = $this->makeTenant('globex-erasure-log', 'Globex Industrial LLC');
        $this->globexOwner = $this->makeMember($this->globex, 'owner@globex-erasure-log.throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function (): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->marlinOwner->getKey();
            $account->save();
            $this->account = $account;
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_anonymizing_a_referenced_contact_masks_its_activity_log_history_without_touching_others(): void
    {
        $contactA = $this->makeContact($this->marlin, $this->marlinOwner, $this->account, [
            'first_name' => 'Xerxes',
            'last_name' => 'Quillfeather',
            'email' => 'xerxes.quillfeather@erasure-test.example',
            'phone' => '555-0192',
        ]);

        $contactB = $this->makeContact($this->marlin, $this->marlinOwner, $this->account, [
            'first_name' => 'Bartholomew',
            'last_name' => 'Winterbourne',
            'email' => 'bartholomew.winterbourne@erasure-test.example',
            'phone' => '555-0193',
        ]);

        $this->makeContact($this->globex, $this->globexOwner, null, [
            'first_name' => 'Demetrius',
            'last_name' => 'Fairweather',
            'email' => 'demetrius.fairweather@erasure-test.example',
            'phone' => '555-0195',
        ]);

        // Câteva update-uri PE contactul care va fi anonimizat — mai multe rânduri
        // `updated`, fiecare cu PII real, ÎNAINTE de erasure (§17.1, doar câmpurile
        // modificate — deci fiecare rând cară o valoare distinctă de telefon).
        TenantContext::run($this->marlin, function () use ($contactA): void {
            $contactA->update(['phone' => '555-0292']);
            $contactA->update(['phone' => '555-0392']);
        });

        // Update MARTOR pe celălalt contact din același tenant — trebuie să rămână în
        // clar după erasure-ul lui A.
        TenantContext::run($this->marlin, function () use ($contactB): void {
            $contactB->update(['phone' => '555-0293']);
        });

        $this->drainQueue();

        // Sanity — testul chiar verifică ceva: PII-ul e prezent înainte de erasure.
        $this->assertGreaterThan(0, $this->countRowsContaining($this->marlin, 'xerxes.quillfeather'));
        $this->assertGreaterThan(0, $this->countRowsContaining($this->marlin, '555-0392'));

        $deal = TenantContext::run($this->marlin, fn (): Deal => $this->dealFor($contactA));

        $anonymized = TenantContext::run($this->marlin, fn (): bool => ContactErasure::erase($contactA->getKey()));
        $this->assertTrue($anonymized, 'Contactul are un deal asociat — trebuie anonimizat, nu șters fizic.');

        $this->drainQueue();

        foreach ([
            'xerxes', 'quillfeather', 'xerxes.quillfeather@erasure-test.example',
            '555-0192', '555-0292', '555-0392',
        ] as $pii) {
            $hits = $this->countRowsContaining($this->marlin, $pii) + $this->countRowsContaining($this->globex, $pii);
            $this->assertSame(0, $hits, "PII-ul erasat „{$pii}” încă apare în activity_log.");
        }

        // Alt contact din ACELAȘI tenant nu e atins.
        $this->assertGreaterThan(0, $this->countRowsContaining($this->marlin, 'bartholomew.winterbourne'));
        $this->assertGreaterThan(0, $this->countRowsContaining($this->marlin, '555-0293'));

        // Un contact din ALT tenant nu e atins.
        $this->assertGreaterThan(0, $this->countRowsContaining($this->globex, 'demetrius.fairweather'));

        // Rămâne vizibil CĂ s-a produs o anonimizare — cheile (câmpurile), nu valorile.
        $maskedRow = TenantContext::run(
            $this->marlin,
            fn () => DB::table('activity_log')
                ->where('auditable_type', Contact::class)
                ->where('auditable_id', $contactA->getKey())
                ->where('action', 'updated')
                ->whereRaw('new_values::text LIKE ?', ['%anonymized_at%'])
                ->first(),
        );
        $this->assertNotNull($maskedRow, 'Rândul `updated` al erasure-ului însuși trebuie să existe, mascat.');
        $newValues = json_decode((string) $maskedRow->new_values, true);
        $oldValues = json_decode((string) $maskedRow->old_values, true);
        $this->assertSame('[anonymized]', $newValues['first_name']);
        $this->assertSame('[anonymized]', $oldValues['first_name']);
        $this->assertArrayHasKey('anonymized_at', $newValues);
        $this->assertArrayHasKey('email', $oldValues);

        // FK-ul deal-ului rămâne intact (BR-CRM-01) — comportament neschimbat de fix-ul de aici.
        $this->assertSame($contactA->getKey(), $deal->fresh()->primary_contact_id);
    }

    public function test_physically_deleting_an_unreferenced_contact_masks_its_activity_log_history(): void
    {
        $contactC = $this->makeContact($this->marlin, $this->marlinOwner, $this->account, [
            'first_name' => 'Cassiopeia',
            'last_name' => 'Thistlewood',
            'email' => 'cassiopeia.thistlewood@erasure-test.example',
            'phone' => '555-0194',
        ]);
        $contactId = $contactC->getKey();

        TenantContext::run($this->marlin, function () use ($contactC): void {
            $contactC->update(['title' => 'Buyer']);
        });

        $this->drainQueue();

        $this->assertGreaterThan(0, $this->countRowsContaining($this->marlin, 'cassiopeia.thistlewood'));

        $anonymized = TenantContext::run($this->marlin, fn (): bool => ContactErasure::erase($contactId));
        $this->assertFalse($anonymized, 'Contactul fără deals/orders trebuie șters fizic, nu anonimizat.');

        $this->drainQueue();

        foreach ([
            'cassiopeia', 'thistlewood', 'cassiopeia.thistlewood@erasure-test.example', '555-0194',
        ] as $pii) {
            $this->assertSame(0, $this->countRowsContaining($this->marlin, $pii), "PII-ul erasat „{$pii}” încă apare în activity_log.");
        }

        // Rândul `deleted` există în continuare — mascat, nu șters din jurnal (§17.1: jurnalul
        // e append-only, `App\Concerns\AppendOnly`).
        $deletedRow = TenantContext::run(
            $this->marlin,
            fn () => DB::table('activity_log')
                ->where('auditable_type', Contact::class)
                ->where('auditable_id', $contactId)
                ->where('action', 'deleted')
                ->first(),
        );
        $this->assertNotNull($deletedRow);
        $oldValues = json_decode((string) $deletedRow->old_values, true);
        $this->assertSame('[anonymized]', $oldValues['first_name']);
        $this->assertArrayHasKey('email', $oldValues);
    }

    /**
     * Restul cursei: un rând de jurnal pentru o modificare ANTERIOARĂ ștergerii, încă în coadă
     * în momentul ei, se scrie după commit cu PII-ul vechi. Simulat printr-o scriere directă
     * după `erase()`; plasa de siguranță (`MaskErasedContactActivityLogJob`), întârziată, îl
     * maschează.
     */
    public function test_a_late_log_row_written_after_erasure_is_masked_by_the_delayed_safety_net(): void
    {
        $contact = $this->makeContact($this->marlin, $this->marlinOwner, $this->account, [
            'first_name' => 'Ignatius',
            'last_name' => 'Pemberton',
            'email' => 'ignatius.pemberton@erasure-test.example',
            'phone' => '555-0196',
        ]);
        $contactId = $contact->getKey();

        $this->drainQueue();

        TenantContext::run($this->marlin, fn (): bool => ContactErasure::erase($contactId));
        $this->drainQueue();

        TenantContext::run($this->marlin, function () use ($contactId): void {
            ActivityLog::query()->create([
                'user_id' => $this->marlinOwner->getKey(),
                'action' => 'updated',
                'auditable_type' => Contact::class,
                'auditable_id' => $contactId,
                'old_values' => ['phone' => '555-0196'],
                'new_values' => ['phone' => '555-0296'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
            ]);
        });

        // Jobul e întârziat — drenarea imediată nu-l atinge încă.
        $this->assertGreaterThan(0, $this->countRowsContaining($this->marlin, '555-0296'));

        $this->travel(MaskErasedContactActivityLogJob::DELAY_SECONDS + 1)->seconds();
        $this->drainQueue();

        $this->assertSame(0, $this->countRowsContaining($this->marlin, '555-0196'));
        $this->assertSame(0, $this->countRowsContaining($this->marlin, '555-0296'));
    }

    /**
     * Scoping-ul plasei de siguranță (`MaskErasedContactActivityLogJob`) e pe
     * `auditable_id`, NU doar pe `auditable_type` — dacă cineva ar scoate acel filtru,
     * un singur erasure ar masca retroactiv istoricul TUTUROR contactelor tenantului,
     * prin coada `default`. Niciun test existent are un contact MARTOR cu un rând scris
     * DUPĂ erasure, în aceeași fereastră de întârziere — fără el, acest scoping n-are ce
     * dovedi (testul de mai jos ar trece la fel de verde cu sau fără filtrul pe id).
     */
    public function test_the_delayed_safety_net_job_only_masks_the_erased_contacts_own_late_rows(): void
    {
        $contact = $this->makeContact($this->marlin, $this->marlinOwner, $this->account, [
            'first_name' => 'Cordelia',
            'last_name' => 'Fenwick',
            'email' => 'cordelia.fenwick@erasure-test.example',
            'phone' => '555-0197',
        ]);
        $contactId = $contact->getKey();

        $witness = $this->makeContact($this->marlin, $this->marlinOwner, $this->account, [
            'first_name' => 'Percival',
            'last_name' => 'Thackeray',
            'email' => 'percival.thackeray@erasure-test.example',
            'phone' => '555-0198',
        ]);
        $witnessId = $witness->getKey();

        $this->drainQueue();

        TenantContext::run($this->marlin, fn (): bool => ContactErasure::erase($contactId));
        $this->drainQueue();

        // Ambele rânduri „întârziate" se scriu DUPĂ erasure, cât timp plasa de siguranță
        // e încă amânată — unul pentru contactul erasat, unul MARTOR, pentru un contact
        // complet neatins de operația de mai sus.
        TenantContext::run($this->marlin, function () use ($contactId, $witnessId): void {
            ActivityLog::query()->create([
                'user_id' => $this->marlinOwner->getKey(),
                'action' => 'updated',
                'auditable_type' => Contact::class,
                'auditable_id' => $contactId,
                'old_values' => ['phone' => '555-0197'],
                'new_values' => ['phone' => '555-0297'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
            ]);

            ActivityLog::query()->create([
                'user_id' => $this->marlinOwner->getKey(),
                'action' => 'updated',
                'auditable_type' => Contact::class,
                'auditable_id' => $witnessId,
                'old_values' => ['phone' => '555-0198'],
                'new_values' => ['phone' => '555-0298'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
            ]);
        });

        $this->travel(MaskErasedContactActivityLogJob::DELAY_SECONDS + 1)->seconds();
        $this->drainQueue();

        $this->assertSame(0, $this->countRowsContaining($this->marlin, '555-0197'));
        $this->assertSame(0, $this->countRowsContaining($this->marlin, '555-0297'));

        // Contactul MARTOR nu e atins de plasa de siguranță — scoping-ul e pe
        // `auditable_id`, nu pe tot tenantul.
        $this->assertGreaterThan(0, $this->countRowsContaining($this->marlin, '555-0198'));
        $this->assertGreaterThan(0, $this->countRowsContaining($this->marlin, '555-0298'));
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeContact(Tenant $tenant, User $owner, ?Account $account, array $overrides): Contact
    {
        return TenantContext::run($tenant, function () use ($owner, $account, $overrides): Contact {
            $contact = new Contact(array_merge([
                'account_id' => $account?->getKey(),
                'first_name' => 'Placeholder',
                'last_name' => 'Placeholder',
                'email' => 'placeholder@erasure-test.example',
                'phone' => '555-0000',
                'title' => 'Buyer',
            ], $overrides));
            $contact->created_by = $owner->getKey();
            $contact->save();

            return $contact;
        });
    }

    private function dealFor(Contact $contact): Deal
    {
        $pipeline = Pipeline::query()->create(['name' => 'Standard']);
        $stage = Stage::query()->create(['pipeline_id' => $pipeline->getKey(), 'name' => 'Qualification', 'position' => 1]);

        $deal = new Deal([
            'account_id' => $this->account->getKey(),
            'primary_contact_id' => $contact->getKey(),
            'pipeline_id' => $pipeline->getKey(),
            'stage_id' => $stage->getKey(),
            'owner_user_id' => $this->marlinOwner->getKey(),
            'title' => 'Annual supply agreement',
            'status' => Deal::STATUS_OPEN,
        ]);
        $deal->created_by = $this->marlinOwner->getKey();
        $deal->save();

        return $deal;
    }

    /**
     * Caută `$needle` în `old_values::text`/`new_values::text`, sub contextul de tenant
     * dat — nu peste o conexiune cu bypass: RLS trebuie să se aplice normal, la fel ca
     * pentru `ActivityLogAnonymizer`/`ContactErasure` înseși.
     */
    private function countRowsContaining(Tenant $tenant, string $needle): int
    {
        return TenantContext::run(
            $tenant,
            fn (): int => DB::table('activity_log')
                ->where(function ($query) use ($needle): void {
                    $query->whereRaw('old_values::text ILIKE ?', ["%{$needle}%"])
                        ->orWhereRaw('new_values::text ILIKE ?', ["%{$needle}%"]);
                })
                ->count(),
        );
    }

    private function drainQueue(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);
    }
}
