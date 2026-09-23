<?php

namespace Tests\Feature\Bulk;

use App\Models\Account;
use App\Models\BulkOperation;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\Scopes\NotAnonymizedContactScope;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Bulk\Actions\ContactBulkDeleteAction;
use App\Support\Bulk\BulkConcurrencyGuard;
use App\Support\Bulk\Resources\ContactBulkResource;
use App\Support\Contacts\ContactErasure;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GDPR-04, §13.5 (Art. 17/21) — operații în masă pe Contacte: opt-out și ștergere/
 * anonimizare RTBF, cap-coadă prin ACELAȘI mecanism ca `ReassignOwnerTest`/
 * `ProductBulkOperationsTest`: dispecerizare HTTP → job planificator → chunk-uri →
 * `Bus::batch()` → stare terminală. RBAC pe cele 4 roluri (§7.4, BR-BULK-02/03), izolare
 * de tenant (ADR-003), pragul de confirmare (FR-BULK-01) și limita de concurență (§22.5).
 *
 * Contactul n-are `owner_user_id` propriu (§8.1): „subsetul propriu" al Agentului e
 * creator (`created_by`) SAU responsabil de cont (`accounts.owner_user_id`) — vezi
 * `App\Support\Bulk\Resources\ContactBulkResource::scopeToOwnRecords()`, simetric cu
 * `App\Policies\ContactPolicy::isWithinOwnRecords()`.
 */
class ContactBulkOperationTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $viewer;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($this->marlin, function (): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
        });

        $this->clearDatabaseTenantContext();
    }

    // ── Opt-out în masă (Art. 21) ─────────────────────────────────────────────────────

    public function test_owner_can_opt_out_only_the_explicitly_selected_contacts(): void
    {
        $ids = TenantContext::run($this->marlin, fn () => [
            $this->contact()->getKey(),
            $this->contact()->getKey(),
            $this->contact()->getKey(),
        ]);
        $this->clearDatabaseTenantContext();

        $targetIds = array_slice($ids, 0, 2);

        $response = $this->actingAs($this->owner)->post('/marlin/contacts/bulk/opt-out', [
            'selectAllMatching' => false,
            'ids' => $targetIds,
        ]);

        $operation = $this->soleOperation('contacts_mark_opted_out');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(2, $operation->total_rows);

        $this->drainBulkQueue();
        $this->assertOperationStatus($operation, BulkOperation::STATUS_COMPLETED);

        $optedOut = TenantContext::run($this->marlin, fn () => Contact::query()->whereIn('id', $targetIds)->pluck('opt_out')->all());
        $this->assertSame([true, true], $optedOut);

        $untouchedId = $ids[2];
        $stillIn = TenantContext::run($this->marlin, fn () => Contact::query()->whereKey($untouchedId)->value('opt_out'));
        $this->assertFalse($stillIn, 'Contactul din AFARA selecției explicite rămâne neatins.');
    }

    /**
     * BR-BULK-02 — un Agent lucrează doar pe subsetul propriu (creator SAU responsabil de
     * cont), chiar dacă selecția explicită include și contacte ale altcuiva.
     */
    public function test_agent_can_only_opt_out_contacts_they_own_even_when_the_selection_shows_more(): void
    {
        [$ownId, $otherIds] = TenantContext::run($this->marlin, fn () => [
            $this->contact(agentOwned: true)->getKey(),
            [$this->contact()->getKey(), $this->contact()->getKey()],
        ]);
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->agent)->post('/marlin/contacts/bulk/opt-out', [
            'selectAllMatching' => false,
            'ids' => [$ownId, ...$otherIds],
        ]);

        $operation = $this->soleOperation('contacts_mark_opted_out');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(1, $operation->total_rows, 'Doar contactul propriu intră în operație, chiar dacă au fost trimise 3 id-uri.');

        $this->drainBulkQueue();

        $this->assertTrue(TenantContext::run($this->marlin, fn () => Contact::query()->whereKey($ownId)->value('opt_out')));

        $othersUntouched = TenantContext::run($this->marlin, fn () => Contact::query()->whereIn('id', $otherIds)->where('opt_out', false)->count());
        $this->assertSame(2, $othersUntouched, 'Contactele altui membru, trimise explicit în ids, rămân neatinse.');
    }

    public function test_viewer_cannot_opt_out_contacts(): void
    {
        $id = TenantContext::run($this->marlin, fn () => $this->contact()->getKey());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->viewer)
            ->post('/marlin/contacts/bulk/opt-out', ['selectAllMatching' => false, 'ids' => [$id]])
            ->assertForbidden();

        $this->assertSame(0, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));
    }

    // ── Ștergere/anonimizare RTBF (Art. 17) ───────────────────────────────────────────

    /**
     * BR-CRM-01/02 — contactul CU referințe (deal) e anonimizat, cel FĂRĂ e șters fizic,
     * în ACEEAȘI operație în masă: fiecare id trece individual prin `App\Support\Contacts\
     * ContactErasure::erase()` (`App\Support\Bulk\Actions\ContactBulkDeleteAction`).
     */
    public function test_owner_can_delete_contacts_with_a_mix_of_anonymized_and_physically_deleted(): void
    {
        [$anonymizedId, $deletedId] = TenantContext::run($this->marlin, function (): array {
            $withDeal = $this->contact();
            $this->dealFor($withDeal);

            $withoutReferences = $this->contact();

            return [$withDeal->getKey(), $withoutReferences->getKey()];
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post('/marlin/contacts/bulk/delete', [
            'selectAllMatching' => false,
            'ids' => [$anonymizedId, $deletedId],
        ]);

        $operation = $this->soleOperation('contacts_delete');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(2, $operation->total_rows);

        $this->drainBulkQueue();
        $this->assertOperationStatus($operation, BulkOperation::STATUS_COMPLETED);

        TenantContext::run($this->marlin, function () use ($anonymizedId, $deletedId): void {
            $anonymized = Contact::withoutGlobalScope(NotAnonymizedContactScope::class)->findOrFail($anonymizedId);
            $this->assertSame('Anonymized', $anonymized->first_name);
            $this->assertTrue($anonymized->opt_out);
            $this->assertTrue($anonymized->isAnonymized());

            $this->assertDatabaseMissing('contacts', ['id' => $deletedId]);
        });
    }

    /** BR-BULK-02, aplicat ștergerii: un Agent atinge doar contactele proprii. */
    public function test_agent_deleting_explicit_ids_including_another_members_contact_only_touches_their_own(): void
    {
        [$ownId, $otherId] = TenantContext::run($this->marlin, fn () => [
            $this->contact(agentOwned: true)->getKey(),
            $this->contact()->getKey(),
        ]);
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->agent)->post('/marlin/contacts/bulk/delete', [
            'selectAllMatching' => false,
            'ids' => [$ownId, $otherId],
        ]);

        $operation = $this->soleOperation('contacts_delete');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(1, $operation->total_rows, 'Doar contactul propriu intră în operație.');

        $this->drainBulkQueue();

        TenantContext::run($this->marlin, function () use ($ownId, $otherId): void {
            $this->assertDatabaseMissing('contacts', ['id' => $ownId]);
            $this->assertDatabaseHas('contacts', ['id' => $otherId]);
        });
    }

    public function test_viewer_cannot_delete_contacts(): void
    {
        $id = TenantContext::run($this->marlin, fn () => $this->contact()->getKey());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->viewer)
            ->post('/marlin/contacts/bulk/delete', ['selectAllMatching' => false, 'ids' => [$id]])
            ->assertForbidden();

        $this->assertSame(0, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));
    }

    // ── Propagarea către activity_log (GDPR-02 × GDPR-04, audit adversarial) ──────────

    /**
     * NICIUN test din suita GDPR-04 verifica, până acum, că ștergerea/anonimizarea în
     * masă chiar PROPAGĂ mascarea `activity_log` — `App\Support\Bulk\Actions\
     * ContactBulkDeleteAction` trece fiecare id individual prin `App\Support\Contacts\
     * ContactErasure::erase()` (docblock-ul ei), care mască ATÂT istoricul preexistent,
     * CÂT ȘI rândul scris asincron de erasure-ul însuși (`App\Observers\
     * ActivityLogObserver`) — dar mecanismul complet nu era exercitat de la capătul HTTP
     * al operației în masă, pe NICIUNA din cele două ramuri (anonimizare, ștergere
     * fizică). Coada `default` (unde scriu atât `WriteActivityLogEntry`, cât și
     * `MaskErasedContactActivityLogJob`) trebuie drenată alături de `bulk` — altfel
     * rândul „erasure" propriu-zis nici nu ajunge scris.
     */
    public function test_bulk_deleting_contacts_masks_their_historical_activity_log_entries(): void
    {
        [$anonymizedId, $deletedId] = TenantContext::run($this->marlin, function (): array {
            $withDeal = $this->contact(overrides: [
                'first_name' => 'Persimmon',
                'last_name' => 'Wraithe',
                'email' => 'persimmon.wraithe@bulk-erasure-test.example',
                'phone' => '555-0771',
            ]);
            $this->dealFor($withDeal);

            $withoutReferences = $this->contact(overrides: [
                'first_name' => 'Ozymandias',
                'last_name' => 'Bellweather',
                'email' => 'ozymandias.bellweather@bulk-erasure-test.example',
                'phone' => '555-0772',
            ]);

            return [$withDeal->getKey(), $withoutReferences->getKey()];
        });

        // Update real, ÎNAINTE de operația în masă — plantează PII în istoricul
        // jurnalului (§17.1), exact ce `ContactErasure::maskActivityLog()` trebuie să
        // acopere retroactiv.
        TenantContext::run($this->marlin, function () use ($anonymizedId, $deletedId): void {
            Contact::query()->whereKey($anonymizedId)->first()->update(['phone' => '555-0871']);
            Contact::query()->whereKey($deletedId)->first()->update(['phone' => '555-0872']);
        });

        $this->drainAllQueues();

        // Sanity — PII-ul chiar există înainte de operația în masă.
        foreach (['persimmon.wraithe', '555-0871', 'ozymandias.bellweather', '555-0872'] as $pii) {
            $this->assertGreaterThan(0, $this->countRowsContaining($this->marlin, $pii), "Setup invalid — „{$pii}” trebuie să existe în clar înainte de ștergerea în masă.");
        }

        $this->actingAs($this->owner)->post('/marlin/contacts/bulk/delete', [
            'selectAllMatching' => false,
            'ids' => [$anonymizedId, $deletedId],
        ]);

        $operation = $this->soleOperation('contacts_delete');
        $this->assertSame(2, $operation->total_rows);

        $this->drainAllQueues();
        $this->assertOperationStatus($operation, BulkOperation::STATUS_COMPLETED);

        foreach ([
            'persimmon', 'wraithe', 'persimmon.wraithe@bulk-erasure-test.example', '555-0771', '555-0871',
            'ozymandias', 'bellweather', 'ozymandias.bellweather@bulk-erasure-test.example', '555-0772', '555-0872',
        ] as $pii) {
            $this->assertSame(0, $this->countRowsContaining($this->marlin, $pii), "PII-ul „{$pii}” a supraviețuit ștergerii în masă — GDPR-02 nu s-a propagat prin ContactBulkDeleteAction.");
        }

        // Rândul scris ASINCRON de erasure-ul însuși (observer → event → listener, coada
        // `default`) trebuie să existe MASCAT, nu absent — la fel ca la o ștergere
        // individuală (`Tests\Feature\Contacts\ContactErasureActivityLogTest`).
        $deletedRow = TenantContext::run(
            $this->marlin,
            fn () => DB::table('activity_log')
                ->where('auditable_type', Contact::class)
                ->where('auditable_id', $deletedId)
                ->where('action', 'deleted')
                ->first(),
        );
        $this->assertNotNull($deletedRow, 'Ștergerea fizică, declanșată din bulk delete, trebuie să scrie rândul `deleted` (mascat) al erasure-ului.');
        $this->assertSame('[anonymized]', json_decode((string) $deletedRow->old_values, true)['first_name']);

        $anonymizedRow = TenantContext::run(
            $this->marlin,
            fn () => DB::table('activity_log')
                ->where('auditable_type', Contact::class)
                ->where('auditable_id', $anonymizedId)
                ->where('action', 'updated')
                ->whereRaw('new_values::text LIKE ?', ['%anonymized_at%'])
                ->first(),
        );
        $this->assertNotNull($anonymizedRow, 'Anonimizarea, declanșată din bulk delete, trebuie să scrie rândul `updated` (mascat) al tranziției de erasure.');
        $this->assertSame('[anonymized]', json_decode((string) $anonymizedRow->new_values, true)['first_name']);
    }

    /**
     * §13.2 pct. 5, BR-BULK-01 — un contact deja anonimizat (ascuns de
     * `NotAnonymizedContactScope`) trimis explicit în `ids` trebuie să dispară tăcut din
     * numărătoare/chunk, la fel ca un id inexistent sau dintr-un alt tenant — NU să
     * producă eroare și NU să treacă a doua oară prin `ContactErasure`.
     */
    public function test_deleting_an_already_anonymized_contact_via_explicit_ids_is_silently_excluded(): void
    {
        [$alreadyAnonymizedId, $normalId] = TenantContext::run($this->marlin, function (): array {
            $withDeal = $this->contact();
            $this->dealFor($withDeal);
            $anonymizedId = $withDeal->getKey();

            $wasAnonymized = ContactErasure::erase($anonymizedId);
            $this->assertTrue($wasAnonymized, 'Setup invalid — contactul cu deal trebuie anonimizat, nu șters fizic.');

            return [$anonymizedId, $this->contact()->getKey()];
        });
        $this->clearDatabaseTenantContext();

        $before = TenantContext::run(
            $this->marlin,
            fn () => Contact::withoutGlobalScope(NotAnonymizedContactScope::class)->findOrFail($alreadyAnonymizedId)->toArray(),
        );

        $response = $this->actingAs($this->owner)->post('/marlin/contacts/bulk/delete', [
            'selectAllMatching' => false,
            'ids' => [$alreadyAnonymizedId, $normalId],
        ]);

        $operation = $this->soleOperation('contacts_delete');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(1, $operation->total_rows, 'Contactul deja anonimizat nu se numără — scopul global îl ascunde din interogare.');

        $this->drainBulkQueue();
        $this->assertOperationStatus($operation, BulkOperation::STATUS_COMPLETED);

        TenantContext::run($this->marlin, function () use ($alreadyAnonymizedId, $before, $normalId): void {
            $after = Contact::withoutGlobalScope(NotAnonymizedContactScope::class)->findOrFail($alreadyAnonymizedId)->toArray();
            $this->assertSame($before, $after, 'Contactul deja anonimizat trebuie să rămână complet neatins — nicio a doua trecere prin ContactErasure.');

            $this->assertDatabaseMissing('contacts', ['id' => $normalId]);
        });
    }

    /**
     * BR-BULK-02 — al doilea braț al `App\Support\Bulk\Resources\
     * ContactBulkResource::scopeToOwnRecords()` (`orWhereHas('account', ...)`) e complet
     * neexercitat de restul suitei: toate testele „agentOwned" folosesc doar
     * `created_by`. Un Agent care N-A CREAT contactul, dar e responsabilul contului
     * (`accounts.owner_user_id`), trebuie să-l poată șterge/anonimiza la fel.
     */
    public function test_agent_can_bulk_delete_a_contact_from_an_account_they_own_even_when_someone_else_created_it(): void
    {
        $contactId = TenantContext::run($this->marlin, function (): string {
            $account = new Account(['name' => 'Agent-Owned Account']);
            $account->owner_user_id = $this->agent->getKey();
            $account->created_by = $this->owner->getKey();
            $account->save();

            $contact = new Contact([
                'account_id' => $account->getKey(),
                'first_name' => 'Griselda',
                'last_name' => 'Ashworth',
                'email' => 'griselda.ashworth@bulk-erasure-test.example',
            ]);
            $contact->created_by = $this->owner->getKey(); // NU agentul — ownership vine din cont.
            $contact->save();

            return $contact->getKey();
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->agent)->post('/marlin/contacts/bulk/delete', [
            'selectAllMatching' => false,
            'ids' => [$contactId],
        ]);

        $operation = $this->soleOperation('contacts_delete');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(1, $operation->total_rows, 'Contactul intră în operație prin ownership-ul de cont, nu prin created_by.');

        $this->drainBulkQueue();
        $this->assertOperationStatus($operation, BulkOperation::STATUS_COMPLETED);

        TenantContext::run($this->marlin, function () use ($contactId): void {
            $this->assertDatabaseMissing('contacts', ['id' => $contactId]);
        });
    }

    /**
     * `App\Support\Bulk\Actions\ContactBulkDeleteAction::apply()` — un id dispărut
     * concurent (contact șters/anonimizat între planificarea chunk-ului și execuția lui,
     * BR-BULK-01) NU trebuie să oprească restul chunk-ului. Direct pe executor, fiindcă
     * simularea unei curse reale prin fluxul HTTP/coadă ar fi nedeterministă; niciun test
     * din suita HTTP exercită azi acest `catch (ModelNotFoundException)`.
     */
    public function test_the_delete_action_skips_a_concurrently_removed_contact_without_aborting_the_chunk(): void
    {
        $existingId = TenantContext::run($this->marlin, fn () => $this->contact()->getKey());
        $vanishedId = (string) Str::ulid();
        $this->clearDatabaseTenantContext();

        $affected = TenantContext::run(
            $this->marlin,
            fn () => (new ContactBulkDeleteAction)->apply(app(ContactBulkResource::class), [$vanishedId, $existingId], []),
        );

        $this->assertSame(1, $affected, 'Doar contactul care chiar există trebuie numărat ca procesat.');

        TenantContext::run($this->marlin, function () use ($existingId): void {
            $this->assertDatabaseMissing('contacts', ['id' => $existingId]);
        });
    }

    // ── Izolare de tenant (ADR-003) ────────────────────────────────────────────────────

    /**
     * Un id dintr-un ALT tenant nu produce nici eroare, nici efect — `Contact::query()`
     * poartă global scope-ul de tenant, deci un id străin dispare tăcut din numărătoare/
     * chunk, exact ca un id inexistent.
     */
    public function test_ids_from_another_tenant_are_silently_ignored(): void
    {
        $other = $this->makeTenant('other', 'Other Co.');
        $otherOwner = $this->makeMember($other, 'demo.other-owner@throughput.dev', Permissions::OWNER);

        $foreignId = TenantContext::run($other, function () use ($otherOwner): string {
            $account = new Account(['name' => 'Foreign Account']);
            $account->created_by = $otherOwner->getKey();
            $account->save();

            $contact = new Contact(['account_id' => $account->getKey(), 'first_name' => 'Foreign', 'last_name' => 'Contact']);
            $contact->created_by = $otherOwner->getKey();
            $contact->save();

            return $contact->getKey();
        });

        $ownId = TenantContext::run($this->marlin, fn () => $this->contact()->getKey());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post('/marlin/contacts/bulk/opt-out', [
            'selectAllMatching' => false,
            'ids' => [$ownId, $foreignId],
        ]);

        $operation = $this->soleOperation('contacts_mark_opted_out');
        $this->assertSame(1, $operation->total_rows, 'Id-ul din alt tenant nu se numără.');

        $this->drainBulkQueue();

        $stillUntouched = TenantContext::run($other, fn () => Contact::query()->whereKey($foreignId)->value('opt_out'));
        $this->assertFalse($stillUntouched, 'Contactul altui tenant rămâne complet neatins.');
    }

    // ── Limita de concurență (§22.5) ───────────────────────────────────────────────────

    public function test_a_bulk_opt_out_is_refused_once_three_operations_are_active(): void
    {
        $id = TenantContext::run($this->marlin, function (): string {
            $contactId = $this->contact()->getKey();

            $this->operation(BulkOperation::STATUS_RUNNING);
            $this->operation(BulkOperation::STATUS_RUNNING);
            $this->operation(BulkOperation::STATUS_PENDING);

            return $contactId;
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->post('/marlin/contacts/bulk/opt-out', ['selectAllMatching' => false, 'ids' => [$id]])
            ->assertSessionHasErrors(['selection' => BulkConcurrencyGuard::refusal()]);

        // Cele 3 rânduri de mai sus rămân — refuzul e ÎNAINTE de crearea uneia noi.
        $this->assertSame(3, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));
    }

    // ── Pragul de confirmare (FR-BULK-01) ─────────────────────────────────────────────

    /**
     * Agent: prag 125 (25% din plafonul de rol de 500, BR-BULK-02). Insert BRUT, nu
     * factory (ca `ReassignOwnerTest::bulkInsertAccounts()`), ca suita să nu încetinească
     * inutil pentru 126 de contacte.
     */
    public function test_an_agent_opt_out_above_the_confirmation_threshold_is_refused_without_the_confirmed_flag(): void
    {
        $this->bulkInsertContacts(126, $this->agent->getKey());

        $this->actingAs($this->agent)
            ->post('/marlin/contacts/bulk/opt-out', ['selectAllMatching' => true])
            ->assertSessionHasErrors('selection');

        $this->assertSame(0, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));
    }

    public function test_an_agent_opt_out_above_the_confirmation_threshold_starts_once_confirmed(): void
    {
        $this->bulkInsertContacts(126, $this->agent->getKey());

        $response = $this->actingAs($this->agent)->post('/marlin/contacts/bulk/opt-out', [
            'selectAllMatching' => true,
            'confirmed' => true,
        ]);

        $operation = $this->soleOperation('contacts_mark_opted_out');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(126, $operation->total_rows);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $overrides */
    private function contact(bool $agentOwned = false, array $overrides = []): Contact
    {
        $contact = new Contact(array_merge([
            'account_id' => $this->account->getKey(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe+'.Str::random(8).'@northwind.test',
        ], $overrides));
        $contact->created_by = $agentOwned ? $this->agent->getKey() : $this->owner->getKey();
        $contact->save();

        return $contact;
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
            'owner_user_id' => $this->owner->getKey(),
            'title' => 'Annual supply agreement',
            'status' => Deal::STATUS_OPEN,
        ]);
        $deal->created_by = $this->owner->getKey();
        $deal->save();

        return $deal;
    }

    /**
     * Insert BRUT — o singură instrucțiune `INSERT` cu N rânduri, ca
     * `ReassignOwnerTest::bulkInsertAccounts()`.
     */
    private function bulkInsertContacts(int $count, string $createdBy): void
    {
        TenantContext::run($this->marlin, function () use ($count, $createdBy): void {
            $now = now();
            $rows = [];

            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $this->marlin->getKey(),
                    'account_id' => $this->account->getKey(),
                    'first_name' => 'Bulk',
                    'last_name' => 'Contact '.$i,
                    'is_primary' => false,
                    'opt_out' => false,
                    'created_by' => $createdBy,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('contacts')->insert($rows);
        });

        $this->clearDatabaseTenantContext();
    }

    private function operation(string $status): BulkOperation
    {
        return BulkOperation::query()->create([
            'user_id' => $this->owner->getKey(),
            'resource_type' => 'contacts',
            'action' => 'contacts_mark_opted_out',
            'filter_snapshot' => [],
            'total_rows' => 1,
            'status' => $status,
        ]);
    }

    private function soleOperation(string $action): BulkOperation
    {
        return TenantContext::run(
            $this->marlin,
            fn () => BulkOperation::query()->where('resource_type', 'contacts')->where('action', $action)->firstOrFail(),
        );
    }

    private function assertOperationStatus(BulkOperation $operation, string $status): void
    {
        $fresh = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertSame($status, $fresh->status);
    }

    /**
     * Drenează coada `bulk` până se golește — planificatorul, chunk-urile ȘI jobul de
     * finalizare (`finally()` al batch-ului) ajung să ruleze, ca într-un worker real.
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

    /**
     * Drenează ATÂT coada `bulk` (planificator + chunk-uri) CÂT ȘI `default`, unde scriu
     * `App\Listeners\Activity\WriteActivityLogEntry` (observerul de model) și
     * `App\Jobs\System\MaskErasedContactActivityLogJob` (plasa de siguranță a
     * `ContactErasure`) — niciunul din cele două nu e dispecerizat pe `bulk`. Fără asta,
     * verificările de propagare GDPR-02 ar vedea rânduri de `activity_log` lipsă, nu
     * mascate, dintr-un motiv de coadă nedrenată, nu de mascare eșuată.
     */
    private function drainAllQueues(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--queue' => 'bulk,default',
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);
    }

    /**
     * Caută `$needle` în `old_values::text`/`new_values::text`, sub contextul tenantului
     * dat — la fel ca `Tests\Feature\Contacts\ContactErasureActivityLogTest`.
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
}
