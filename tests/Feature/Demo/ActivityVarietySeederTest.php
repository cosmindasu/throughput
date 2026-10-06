<?php

namespace Tests\Feature\Demo;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Pipeline;
use App\Models\Product;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Activity\ActivityKind;
use App\Support\Permissions;
use Database\Seeders\Demo\ActivityVarietySeeder;
use Database\Seeders\Support\ActivityLogRecorder;
use Database\Seeders\Support\ChunkedWriter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Jurnalul de activitate e un argument de vânzare al produsului, deci setul demo trebuie
 * să-l arate ca pe unul real: mai multe feluri de eveniment, nu același verb repetat.
 *
 * Testul rulează DOAR `ActivityVarietySeeder`, pe o mână de entități construite aici — nu
 * `DemoDatasetSeeder` întreg, care durează minute și n-ar spune nimic în plus despre regula
 * verificată. Ce apără:
 *
 *  1. **Varietatea însăși.** Celelalte seedere produc două verbe din nouă, iar cele mai
 *     recente intrări erau toate mutări de etapă — „Recent activity" repeta de opt ori
 *     aceeași frază. Pragul de mai jos e pe `ActivityKind`, nu pe `action`: trei dintre
 *     felurile care contează (factură încasată, comandă expediată, mutare de etapă) sunt
 *     toate `updated` în coloană, deci o numărătoare pe `action` le-ar vedea ca unul singur.
 *  2. **Proporția.** Autentificările sunt cel mai frecvent eveniment dintr-un sistem real,
 *     dar un feed în care patru rânduri din cinci spun „cineva s-a autentificat" e la fel de
 *     inutil ca unul monoton.
 *  3. **Trecutul.** Un jurnal descrie ce S-A întâmplat. Vezi `DemoClockTest` pentru cealaltă
 *     jumătate a aceleiași reguli.
 */
class ActivityVarietySeederTest extends TestCase
{
    private Tenant $tenant;

    /** @var array{owner_id: string, demo_agent_id: ?string, pool: list<array{id: string, role: string}>} */
    private array $staff;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin');
        $owner = $this->makeMember($this->tenant, 'demo.owner@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->tenant, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->staff = [
            'owner_id' => $owner->getKey(),
            'demo_agent_id' => $agent->getKey(),
            'pool' => [
                ['id' => $owner->getKey(), 'role' => Permissions::OWNER],
                ['id' => $agent->getKey(), 'role' => Permissions::AGENT],
            ],
        ];

        $this->seedSubjects($owner);
        $this->clearDatabaseTenantContext();
    }

    public function test_the_seeded_tail_covers_most_of_the_activity_kinds(): void
    {
        $kinds = $this->runSeederAndCollectKinds();

        // Cele nouă valori ale enum-ului, plus felurile DERIVATE, minus TREI absențe
        // deliberate: `member_deactivated` (setul demo n-are niciun membru dezactivat, iar o
        // intrare fără membership în spate ar fi o afirmație falsă), `order_shipped` și
        // `invoice_paid`, pe care seederele de business le scriu deja pentru FIECARE
        // înregistrare, datate pe evenimentul real — expedierea în `StockAndOrdersSeeder`,
        // încasarea în `BillingSeeder`. Dublate aici, ar fi o a doua expediere a aceleiași
        // comenzi sau o a doua plată a aceleiași facturi, la altă oră.
        $expected = ['login', 'login_failed', 'exported', 'imported', 'bulk_action', 'role_changed', 'deleted', 'created', 'updated'];

        foreach (['order_shipped' => 'expedierile vin din lanțul de comenzi', 'invoice_paid' => 'încasările vin din lanțul de facturare'] as $absent => $motiv) {
            $this->assertArrayNotHasKey($absent, $kinds, "{$motiv}, nu din coada de varietate");
        }

        foreach ($expected as $kind) {
            $this->assertArrayHasKey($kind, $kinds, "felul `{$kind}` lipsește din coada semănată — feed-ul îl pierde");
        }
    }

    public function test_logins_do_not_drown_out_the_business_events(): void
    {
        $kinds = $this->runSeederAndCollectKinds();
        $total = array_sum($kinds);
        $sessions = ($kinds['login'] ?? 0) + ($kinds['login_failed'] ?? 0);

        $this->assertLessThan(
            0.4,
            $sessions / $total,
            "autentificările sunt {$sessions} din {$total} de rânduri — feed-ul devine un registru de prezență",
        );
    }

    /**
     * Trei afirmații, nu una. „Nimic în viitor" singur e o gardă slabă: o versiune care pune
     * TOATE evenimentele la `now()` o trece — adică exact artefactul pentru care a fost scris
     * `DemoClock` („opt evenimente din ultimele cinci minute, toate la minutul resetului").
     *
     * Ceasul e ÎNGHEȚAT pe o oră de dimineață: fără asta, puterea de detecție a primei
     * aserțiuni depinde de ora la care rulează suita — o tragere din orele de birou e în
     * viitor la 8 dimineața și în trecut la 20:00.
     */
    public function test_the_seeded_tail_is_in_the_past_spread_out_and_never_just_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 07:30:00'));

        $this->runSeederAndCollectKinds();

        TenantContext::run($this->tenant, function (): void {
            $this->assertSame(0, ActivityLog::query()->where('created_at', '>', now())->count(), 'un jurnal descrie ce s-a întâmplat, nu ce urmează');

            // Marginea de o oră a lui `DemoClock`: nimic „chiar acum".
            $this->assertSame(0, ActivityLog::query()->where('created_at', '>', now()->subMinutes(59))->count(), 'nimic nu se întâmplă chiar la minutul resetului');

            // …și chiar se întinde: o coadă strânsă într-o zi ar trece primele două aserțiuni.
            $oldest = ActivityLog::query()->min('created_at');
            $newest = ActivityLog::query()->max('created_at');

            $this->assertGreaterThan(
                2 * 24 * 3600,
                Carbon::parse($newest)->diffInSeconds(Carbon::parse($oldest), absolute: true),
                'coada trebuie să acopere zile, nu minute',
            );
        });
    }

    /**
     * `deleted` trimite deliberat la un id inexistent — ce rămâne după o ștergere. Feed-ul
     * afișează atunci `subjectName: null` în loc să promită un link către o înregistrare
     * care încă există, iar asta e singurul mod în care fraza „Deleted …" poate fi adevărată.
     */
    public function test_a_deletion_points_at_a_record_that_is_really_gone(): void
    {
        $this->runSeederAndCollectKinds();

        TenantContext::run($this->tenant, function (): void {
            $deletions = ActivityLog::query()->where('action', 'deleted')->get();

            $this->assertNotEmpty($deletions);

            foreach ($deletions as $entry) {
                $class = $entry->auditable_type;
                $this->assertNotNull($entry->auditable_id);
                $this->assertNull(
                    $class::query()->whereKey($entry->auditable_id)->first(),
                    'o ștergere semănată trimite la o înregistrare care încă există',
                );
            }
        });
    }

    /** @return array<string, int> felul → numărul de rânduri */
    private function runSeederAndCollectKinds(): array
    {
        TenantContext::run($this->tenant, function (): void {
            $recorder = new ActivityLogRecorder(new ChunkedWriter(ActivityLog::class, 1000));
            (new ActivityVarietySeeder)->run($this->tenant, $this->staff, null, $recorder);
        });

        return TenantContext::run($this->tenant, function (): array {
            $kinds = [];

            foreach (ActivityLog::query()->get() as $entry) {
                $kind = ActivityKind::of($entry);
                $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            }

            return $kinds;
        });
    }

    /**
     * Minimul de care seeder-ul are nevoie ca să găsească subiecte reale: un cont cu un
     * contact, o afacere, o comandă ONORATĂ și o factură ÎNCASATĂ (celelalte stări nu sunt
     * citite), plus un produs.
     */
    private function seedSubjects(User $owner): void
    {
        TenantContext::run($this->tenant, function () use ($owner): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $owner->getKey();
            $account->save();

            $contact = new Contact([
                'account_id' => $account->getKey(),
                'first_name' => 'Dana',
                'last_name' => 'Whitfield',
                'email' => 'dana.whitfield@northwind.test',
            ]);
            $contact->created_by = $owner->getKey();
            $contact->save();

            $pipeline = Pipeline::query()->create(['name' => 'Standard']);
            $stage = Stage::query()->create(['pipeline_id' => $pipeline->getKey(), 'name' => 'Qualification', 'position' => 1]);

            $deal = new Deal([
                'account_id' => $account->getKey(),
                'pipeline_id' => $pipeline->getKey(),
                'stage_id' => $stage->getKey(),
                'owner_user_id' => $owner->getKey(),
                'title' => 'Annual fastener supply agreement',
                'status' => Deal::STATUS_OPEN,
            ]);
            $deal->created_by = $owner->getKey();
            $deal->save();

            $order = new Order([
                'account_id' => $account->getKey(),
                'owner_user_id' => $owner->getKey(),
                'status' => Order::STATUS_FULFILLED,
                'grand_total' => 4_217.44,
            ]);
            $order->created_by = $owner->getKey();
            $order->save();

            $invoice = new Invoice(['status' => Invoice::STATUS_PAID, 'total' => 4_217.44, 'balance_due' => 0]);
            $invoice->order_id = $order->getKey();
            $invoice->save();

            Product::query()->create(['name' => 'Hex bolt M8', 'unit_of_measure' => 'each']);
        });
    }
}
