<?php

namespace Tests\Feature\Activity;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FR-AUD-03, §7.4 (rândul „Jurnal de activitate") — Owner/Manager văd tot tenantul, Agentul
 * doar acțiunile proprii, Viewer-ul deloc. Distinct de tab-ul „History" al unei entități
 * (`ActivityLogEntityHistoryTest`), fără restricția de rol de mai jos.
 */
class ActivityLogIndexTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $agent;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($this->marlin, function (): void {
            ActivityLog::query()->create([
                'user_id' => $this->owner->getKey(),
                'action' => 'updated',
                'auditable_type' => 'App\\Models\\Account',
                'auditable_id' => (string) Str::ulid(),
                'old_values' => ['name' => 'Old'],
                'new_values' => ['name' => 'New'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
            ]);
            ActivityLog::query()->create([
                'user_id' => $this->agent->getKey(),
                'action' => 'created',
                'auditable_type' => 'App\\Models\\Account',
                'auditable_id' => (string) Str::ulid(),
                'new_values' => ['name' => 'Agent made this'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
            ]);
        });
        $this->clearDatabaseTenantContext();
    }

    /**
     * Contractul paginii: `kind`, `description` și `subjectName` (`generated.d.ts`, regula 2).
     *
     * Testul ăsta lipsea, și lipsa lui nu era inofensivă. Rândurile din `setUp()` au
     * `auditable_id` ULID inventat, deci `subjectName` iese `null` pe toate — adică fix calea
     * interesantă rămânea neexercitată. Mai grav, `with('auditable')` din controller putea fi
     * scos fără ca niciun test să se înroșească: în producție `preventLazyLoading` e dezactivat
     * (`AppServiceProvider` îl leagă de `! isProduction()`), deci acolo ar fi devenit un N+1
     * tăcut, nu o excepție.
     *
     * De-aia rândurile de aici trimit către înregistrări REALE.
     */
    public function test_the_page_carries_the_derived_kind_the_phrase_and_the_subject_name(): void
    {
        $deal = null;

        TenantContext::run($this->marlin, function () use (&$deal): void {
            ActivityLog::query()->delete();

            $account = new Account(['name' => 'Northgate Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();

            $deal = $this->dealFor($account);

            $this->logRow('updated', Account::class, $account->getKey(), ['name' => 'Northgate Industrial Supply LLC']);
            // `stage_id` în `new_values` e singurul lucru care deosebește o mutare de etapă de
            // o editare de titlu — amândouă sunt `updated` în coloană.
            $this->logRow('updated', Deal::class, $deal->getKey(), ['stage_id' => (string) Str::ulid()]);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get('/marlin/activity');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('entries.data', 2)
            // Ordinea e descrescătoare pe `created_at` + `id`, deci rândul cu deal-ul e primul.
            ->where('entries.data.0.kind', 'stage_moved')
            ->where('entries.data.0.description', 'Moved Deal to another stage')
            ->where('entries.data.0.subjectName', $deal->title)
            ->where('entries.data.1.kind', 'updated')
            ->where('entries.data.1.description', 'Updated Account')
            ->where('entries.data.1.subjectName', 'Northgate Industrial Supply LLC'));
    }

    /**
     * Rândul de ȘTERGERE e singurul la care `auditable` e `null` prin construcție, deci
     * singurul care nu putea spune CARE înregistrare — exact întrebarea pentru care există
     * câmpul. Numele vine din instantaneul `old_values`, iar linkul trebuie să DISPARĂ:
     * entitatea nu mai există, deci ar fi dus la 404.
     */
    public function test_a_deleted_row_keeps_its_name_from_the_snapshot_and_drops_the_link(): void
    {
        TenantContext::run($this->marlin, function (): void {
            ActivityLog::query()->delete();
            $this->logRow('deleted', Account::class, (string) Str::ulid(), null, ['name' => 'Ashworth Bolt & Fastener Inc.']);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get('/marlin/activity');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('entries.data.0.kind', 'deleted')
            ->where('entries.data.0.subjectName', 'Ashworth Bolt & Fastener Inc.')
            ->where('entries.data.0.entityUrl', null));
    }

    /**
     * Fereastra reală de scurgere: un rând rămâne al tău prin `user_id` chiar după ce ai
     * pierdut dreptul pe înregistrarea atinsă.
     *
     * Jurnalul îngustează pe ACTOR, nu pe vizibilitatea înregistrării, iar `Invoice` e
     * singurul tip cu îngustare pe rând (`InvoicePolicy::view()` cere ca owner-ul comenzii să
     * fie utilizatorul). Un Manager retrogradat în Agent — sau un Agent căruia i s-a
     * reatribuit comanda — continua să vadă NUMĂRUL facturii în `subjectName`, plus un link
     * care răspunde 403.
     *
     * Rândul TREBUIE să rămână: e acțiunea lui, iar un jurnal de audit care își omite propriile
     * intrări spune o neadevărată. Dispar doar numele și linkul.
     */
    public function test_an_agent_who_lost_access_to_an_invoice_no_longer_sees_its_number(): void
    {
        $altcineva = $this->makeMember($this->marlin, 'other.agent@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->marlin, function () use ($altcineva): void {
            ActivityLog::query()->delete();

            $account = new Account(['name' => 'Cedarport Fasteners Co.']);
            $account->created_by = $this->owner->getKey();
            $account->save();

            // Comanda e a ALTCUIVA, deci `InvoicePolicy::view()` îi refuză factura Agentului.
            $order = new Order([
                'order_number' => 'MRL-10001',
                'account_id' => $account->getKey(),
                'owner_user_id' => $altcineva->getKey(),
                'status' => 'confirmed',
                'currency' => 'USD',
                'subtotal' => 100, 'discount_total' => 0, 'shipping_total' => 0, 'grand_total' => 100,
            ]);
            $order->created_by = $this->owner->getKey();
            $order->save();

            $invoice = new Invoice([
                'order_id' => $order->getKey(),
                'invoice_number' => 'MRL-INV-5018',
                'status' => Invoice::STATUS_SENT,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => 'USD',
                'subtotal' => 100, 'tax_total' => 0, 'total' => 100, 'amount_paid' => 0, 'balance_due' => 100,
            ]);
            $invoice->save();

            // Rândul e al AGENTULUI: l-a scris pe vremea când avea dreptul.
            ActivityLog::query()->create([
                'user_id' => $this->agent->getKey(),
                'action' => 'created',
                'auditable_type' => Invoice::class,
                'auditable_id' => $invoice->getKey(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
            ]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->agent)->get('/marlin/activity')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Rândul RĂMÂNE — e acțiunea lui.
                ->has('entries.data', 1)
                ->where('entries.data.0.description', 'Created Invoice')
                // ...dar fără numărul facturii și fără link către un 403.
                ->where('entries.data.0.subjectName', null)
                ->where('entries.data.0.entityUrl', null));

        // Owner-ul, care poate vedea orice factură din tenant, vede și numele. `has(1)` face
        // `.0` să însemne „singurul rând", nu „primul după sortare".
        $this->actingAs($this->owner)->get('/marlin/activity')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.subjectName', 'MRL-INV-5018'));
    }

    /** Un deal minim, fără evenimente de etapă: testul se uită la jurnal, nu la pipeline. */
    private function dealFor(Account $account): Deal
    {
        $pipeline = Pipeline::query()->create(['name' => 'Sales', 'is_default' => true]);
        $stage = Stage::query()->create([
            'pipeline_id' => $pipeline->getKey(),
            'name' => 'New',
            'position' => 1,
            'probability' => 10,
        ]);

        $deal = new Deal([
            'account_id' => $account->getKey(),
            'pipeline_id' => $pipeline->getKey(),
            'stage_id' => $stage->getKey(),
            'owner_user_id' => $this->owner->getKey(),
            'title' => 'Annual Fittings supply agreement',
            'value' => 5000.00,
            'status' => Deal::STATUS_OPEN,
        ]);
        $deal->created_by = $this->owner->getKey();
        $deal->save();

        return $deal;
    }

    private function logRow(string $action, string $type, string $id, ?array $new = null, ?array $old = null): void
    {
        ActivityLog::query()->create([
            'user_id' => $this->owner->getKey(),
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PestTest/1.0',
        ]);
    }

    public function test_owner_sees_every_row_in_the_tenant(): void
    {
        $response = $this->actingAs($this->owner)->get('/marlin/activity');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Activity/Index')
            ->has('entries.data', 2)
            ->where('canFilterByUser', true));
    }

    public function test_agent_sees_only_their_own_actions(): void
    {
        $response = $this->actingAs($this->agent)->get('/marlin/activity');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Activity/Index')
            ->has('entries.data', 1)
            ->where('entries.data.0.actor.id', $this->agent->getKey())
            ->where('canFilterByUser', false)
            ->where('members', []));
    }

    public function test_viewer_cannot_access_the_tenant_wide_journal(): void
    {
        $this->actingAs($this->viewer)->get('/marlin/activity')->assertForbidden();
    }

    /**
     * `App\Http\Middleware\HandleInertiaRequests::navigationPermissions()` — clé combinée
     * pentru `NavItem` (un singur `permission` per intrare, `AppLayout.tsx`): adevărat
     * pentru Owner/Agent (fiecare are UNA din cele două permisiuni), fals pentru Viewer.
     */
    public function test_the_combined_nav_permission_reflects_either_underlying_permission(): void
    {
        // `Record<string, boolean>` e un obiect PLAT (chei „accounts.view" literale, nu
        // imbricate): `AssertableJson::where()` cu un path pe puncte ar naviga „navigation
        // → activity_log → any_view" ca TREI niveluri (`Arr::get()`, fără suport de escape
        // în această versiune) — se citește cheia PLATĂ direct, printr-un closure pe
        // `navigation` ca întreg.
        $this->actingAs($this->owner)->get('/marlin/dashboard')
            ->assertInertia(fn ($page) => $page->where('navigation', fn ($navigation) => $navigation['activity_log.any_view'] === true));

        $this->actingAs($this->agent)->get('/marlin/dashboard')
            ->assertInertia(fn ($page) => $page->where('navigation', fn ($navigation) => $navigation['activity_log.any_view'] === true));

        $this->actingAs($this->viewer)->get('/marlin/dashboard')
            ->assertInertia(fn ($page) => $page->where('navigation', fn ($navigation) => $navigation['activity_log.any_view'] === false));
    }

    public function test_filtering_by_action_narrows_the_list(): void
    {
        $response = $this->actingAs($this->owner)->get('/marlin/activity?action=created');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Activity/Index')
            ->has('entries.data', 1)
            ->where('entries.data.0.action', 'created'));
    }
}
