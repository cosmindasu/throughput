<?php

namespace Tests\Feature\Activity;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Activity\ActivityVisibility;
use App\Support\Activity\ChangedAttributes;
use App\Support\Permissions;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `ActivityVisibility` pe TOATE suprafețele care o folosesc și pe TOATE rolurile, nu doar pe
 * perechea „Agent fără acces vs Owner" din `ActivityLogIndexTest`.
 *
 * Fixtura are DOUĂ facturi — una pe comanda Agentului, una pe comanda unui rival — și rânduri
 * de jurnal ale Agentului pe ambele. Așa, „maschează tot" și „nu maschează nimic" cad
 * amândouă: doar decizia PE RÂND trece.
 *
 * De ce controlul pozitiv e partea importantă: `mayNameSubject()` cade ÎNCHIS când relația
 * `order` nu e încărcată. Fără un rând pe care Agentul TREBUIE să-l vadă, un test „Agentul nu
 * vede numele" trece la fel de bine când `morphWith` lipsește — adică nu deosebește „masca
 * funcționează" de „relația nu se încarcă niciodată". Pe datele demo, a doua variantă ar fi
 * șters numele de pe toate rândurile de factură ale celor 11 Agenți, fără nicio eroare.
 */
class ActivityVisibilityTest extends TestCase
{
    private const MINE = 'MRL-INV-1001';

    private const THEIRS = 'MRL-INV-2002';

    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $rival;

    private User $viewer;

    private Account $account;

    /** Factura de pe comanda AGENTULUI — o poate vedea. */
    private Invoice $mine;

    /** Factura de pe comanda RIVALULUI — Agentul n-are drept pe ea. */
    private Invoice $theirs;

    /** @var array<string, string> cheie logică => id rând `activity_log` */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);
        $this->rival = $this->makeMember($this->marlin, 'rival.agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($this->marlin, function (): void {
            $this->account = new Account(['name' => 'Cedarport Fasteners Co.']);
            $this->account->created_by = $this->owner->getKey();
            $this->account->save();

            $this->mine = $this->invoiceOn($this->agent, 'MRL-10001', self::MINE);
            $this->theirs = $this->invoiceOn($this->rival, 'MRL-10002', self::THEIRS);

            // `new_values` ca pe calea REALĂ: `ActivityLogObserver::created()` scrie
            // instantaneul complet, deci rândul poartă `invoice_number` și totalurile.
            // Seed-ul demo le scrie NULL — de-aia scurgerea nu se vede în baza de dezvoltare.
            $this->rows['mine'] = $this->logRow($this->agent, $this->mine, now()->subMinutes(4), $this->snapshotOf($this->mine));
            $this->rows['theirs'] = $this->logRow($this->agent, $this->theirs, now()->subMinutes(3), $this->snapshotOf($this->theirs));
            $this->rows['account'] = $this->logRow($this->agent, $this->account, now()->subMinutes(2));
            $this->rows['theirsByManager'] = $this->logRow($this->manager, $this->theirs, now()->subMinute());
        });
        $this->clearDatabaseTenantContext();
    }

    // -------------------------------------------------------------------------------------
    // `/activity` — `ActivityLogResource`
    // -------------------------------------------------------------------------------------

    /**
     * CONTROLUL POZITIV. Se înroșește dacă `morphWith` dispare (relația nu se încarcă, deci
     * decizia cade închis), dacă `order:id,owner_user_id` pierde coloana, dacă comparația se
     * inversează sau dacă masca se aplică oricărui Agent.
     *
     * Tot aici e singura aserțiune POZITIVĂ pe `entityUrl` din suită: până acum câmpul putea
     * fi înlocuit cu `null` fix și toate testele rămâneau verzi.
     */
    public function test_an_agent_keeps_the_name_the_link_and_the_values_of_an_invoice_they_own(): void
    {
        $entries = $this->entriesAs($this->agent, '/marlin/activity', 'entries.data');

        $mine = $entries[$this->rows['mine']];
        $this->assertSame(self::MINE, $mine['subjectName']);
        $this->assertSame("/marlin/invoices/{$this->mine->getKey()}", $mine['entityUrl']);
        $this->assertSame(self::MINE, $mine['newValues']['invoice_number'] ?? null);

        // Un tip fără îngustare pe rând nu e atins de mască.
        $account = $entries[$this->rows['account']];
        $this->assertSame('Cedarport Fasteners Co.', $account['subjectName']);
        $this->assertSame("/marlin/accounts/{$this->account->getKey()}", $account['entityUrl']);
    }

    /**
     * Rândul RĂMÂNE (e acțiunea Agentului), dar fără nume, fără link și fără valori. Cele trei
     * se aserțează SEPARAT, ca o mască aplicată doar unora să spună cărei anume îi lipsește.
     */
    public function test_an_agent_who_lost_the_order_keeps_the_row_but_loses_name_link_and_values(): void
    {
        $entries = $this->entriesAs($this->agent, '/marlin/activity', 'entries.data');

        // Doar rândurile LUI: cel al managerului nu e al lui, deci nu apare.
        $this->assertCount(3, $entries);
        $this->assertArrayNotHasKey($this->rows['theirsByManager'], $entries);

        $this->assertArrayHasKey($this->rows['theirs'], $entries, 'Rândul trebuie să rămână în jurnalul propriu.');
        $theirs = $entries[$this->rows['theirs']];
        $this->assertSame('Created Invoice', $theirs['description']);
        $this->assertNull($theirs['subjectName'], 'subjectName scurge numărul facturii.');
        $this->assertNull($theirs['entityUrl'], 'entityUrl duce la un 403 și scurge id-ul facturii.');
        $this->assertNull($theirs['newValues'], 'newValues poartă instantaneul complet, cu tot cu numărul.');
    }

    /**
     * Owner și Manager nu sunt mascați NICIODATĂ — văd tot tenantul și pot deschide orice
     * factură. Ucide mutația „maschează pe oricine nu e Owner" și pe „maschează pe oricine nu
     * e proprietarul comenzii", pe care perechea Agent-vs-Owner nu le vede.
     */
    public function test_owner_and_manager_are_never_masked_whoever_owns_the_order(): void
    {
        foreach ([$this->owner, $this->manager] as $user) {
            $entries = $this->entriesAs($user, '/marlin/activity', 'entries.data');

            foreach (['mine' => self::MINE, 'theirs' => self::THEIRS, 'theirsByManager' => self::THEIRS] as $cheie => $numar) {
                $rand = $entries[$this->rows[$cheie]];
                $this->assertSame($numar, $rand['subjectName'], "{$user->email} / {$cheie}");
                $this->assertNotNull($rand['entityUrl'], "{$user->email} / {$cheie}");
            }

            $this->assertSame(self::THEIRS, $entries[$this->rows['theirs']]['newValues']['invoice_number'] ?? null, $user->email);
        }
    }

    /**
     * Căutarea e pe TOT răspunsul, nu pe un câmp anume: orice cale de scurgere o prinde, și pe
     * cele pe care nu le-am prevăzut. Controlul pozitiv (Owner-ul TREBUIE să vadă numărul în
     * aceeași formă de răspuns) împiedică testul să treacă degeaba dacă pagina n-ar randa
     * payload-ul cum credem.
     */
    public function test_the_lost_invoice_number_does_not_travel_in_the_payload_by_any_path(): void
    {
        $alOwnerului = $this->actingAs($this->owner)->get('/marlin/activity')->assertOk()->getContent();
        $this->assertStringContainsString(self::THEIRS, $alOwnerului, 'Control pozitiv: Owner-ul trebuie să vadă numărul.');

        $alAgentului = $this->actingAs($this->agent)->get('/marlin/activity')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::THEIRS, $alAgentului, 'Numărul facturii pierdute a plecat în payload.');
        $this->assertStringNotContainsString((string) $this->theirs->getKey(), $alAgentului, 'Id-ul facturii pierdute a plecat în payload.');
        $this->assertStringContainsString(self::MINE, $alAgentului, 'Control pozitiv: factura PROPRIE rămâne numită.');
    }

    /**
     * Un rând de ȘTERGERE n-are model (`auditable` iese `null`), dar `old_values` îi păstrează
     * numărul, iar `ActivityNarrative::deletedSubjectName()` îl citește de acolo. De-aia masca
     * decide pe `auditable_type` al RÂNDULUI, nu pe modelul încărcat.
     *
     * Azi nicio cale din aplicație nu șterge facturi (se folosește `void`), deci testul fixează
     * o fereastră închisă ÎNAINTE să se deschidă.
     */
    public function test_a_deleted_invoice_row_is_masked_too_because_the_right_cannot_be_proven(): void
    {
        TenantContext::run($this->marlin, function (): void {
            ActivityLog::query()->create([
                'user_id' => $this->agent->getKey(),
                'action' => 'deleted',
                'auditable_type' => Invoice::class,
                'auditable_id' => (string) Str::ulid(),
                'old_values' => ['invoice_number' => 'MRL-INV-9009', 'total' => '100.00'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
            ]);
        });
        $this->clearDatabaseTenantContext();

        $alAgentului = $this->actingAs($this->agent)->get('/marlin/activity')->assertOk()->getContent();
        $this->assertStringNotContainsString('MRL-INV-9009', $alAgentului);

        // Owner-ul îl vede: factura a existat, iar el avea dreptul pe ea.
        $alOwnerului = $this->actingAs($this->owner)->get('/marlin/activity')->assertOk()->getContent();
        $this->assertStringContainsString('MRL-INV-9009', $alOwnerului);
    }

    // -------------------------------------------------------------------------------------
    // Dashboard — `ActivityEntryResource`
    // -------------------------------------------------------------------------------------

    /** Feed-ul de pe dashboard folosește ALT Resource: fără asta, putea fi revenit în tăcere. */
    public function test_the_dashboard_feed_names_an_owned_invoice_and_masks_a_lost_one(): void
    {
        $entries = $this->entriesAs($this->agent, '/marlin/dashboard', 'activity');

        $this->assertCount(3, $entries);
        $this->assertSame(self::MINE, $entries[$this->rows['mine']]['subjectName']);
        $this->assertSame('Cedarport Fasteners Co.', $entries[$this->rows['account']]['subjectName']);

        // Fraza rămâne, numele dispare.
        $this->assertSame('Created Invoice', $entries[$this->rows['theirs']]['description']);
        $this->assertNull($entries[$this->rows['theirs']]['subjectName']);
    }

    public function test_the_dashboard_feed_never_masks_owner_or_manager(): void
    {
        foreach ([$this->owner, $this->manager] as $user) {
            $entries = $this->entriesAs($user, '/marlin/dashboard', 'activity');

            $this->assertCount(4, $entries, $user->email);
            $this->assertSame(self::THEIRS, $entries[$this->rows['theirs']]['subjectName'], $user->email);
            $this->assertSame(self::THEIRS, $entries[$this->rows['theirsByManager']]['subjectName'], $user->email);
            $this->assertSame(self::MINE, $entries[$this->rows['mine']]['subjectName'], $user->email);
        }
    }

    // -------------------------------------------------------------------------------------
    // Tab-ul „History" — `ActivityLogController::forEntity()`
    // -------------------------------------------------------------------------------------

    /**
     * Aici masca n-are ce ascunde: `Gate::authorize('view')` refuză Agentul fără acces ÎNAINTE
     * de orice rând. Testul fixează exact asta, ca masca să nu devină pe tăcute singura
     * apărare a unei rute care are deja una — și ca un Agent cu acces, un Viewer și un Manager
     * să-și vadă în continuare numărul (relația vine din `loadMissing`, nu din `eagerLoad`).
     */
    public function test_the_history_tab_is_gated_by_the_policy_and_names_the_invoice_for_whoever_passes(): void
    {
        $this->actingAs($this->agent)
            ->getJson("/marlin/activity/entity/invoice/{$this->mine->getKey()}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subjectName', self::MINE);

        $this->actingAs($this->agent)
            ->getJson("/marlin/activity/entity/invoice/{$this->theirs->getKey()}")
            ->assertForbidden();

        foreach ([$this->viewer, $this->manager] as $user) {
            $randuri = $this->actingAs($user)
                ->getJson("/marlin/activity/entity/invoice/{$this->theirs->getKey()}")
                ->assertOk()
                ->assertJsonCount(2, 'data')
                ->json('data');

            foreach ($randuri as $rand) {
                $this->assertSame(self::THEIRS, $rand['subjectName'], $user->email);
            }
        }
    }

    // -------------------------------------------------------------------------------------
    // Regula însăși
    // -------------------------------------------------------------------------------------

    /**
     * Masca e o COPIE a regulii din `InvoicePolicy::view()`, nu o derivare din ea — aici se
     * verifică echivalența, pe toate rolurile și pe ambele facturi. Dacă policy-ul se îngustează
     * vreodată (un rol nou, un al doilea criteriu), testul se înroșește ÎN LOCUL unei
     * divergențe tăcute între ce poți deschide și ce ți se numește în jurnal.
     *
     * Precondiția e cea documentată în `ActivityVisibility`: relația `order` adusă de
     * `eagerLoad()`. Fără ea masca întoarce `false` și pentru un Agent îndreptățit — ramură
     * fixată separat, mai jos.
     */
    public function test_the_mask_agrees_with_the_invoice_policy_for_every_role(): void
    {
        $this->inTenant(function (): void {
            foreach ([$this->owner, $this->manager, $this->agent, $this->rival, $this->viewer] as $user) {
                foreach (['mine' => $this->mine, 'theirs' => $this->theirs] as $cheie => $factura) {
                    $proaspata = Invoice::query()->with('order:id,owner_user_id')->findOrFail($factura->getKey());

                    $this->assertSame(
                        Gate::forUser($user)->allows('view', $proaspata),
                        ActivityVisibility::mayNameSubject($this->rowFor($proaspata), $user),
                        "{$user->email} / {$cheie}",
                    );
                }
            }
        });
    }

    /**
     * Comanda e a AGENTULUI, deci răspunsul „corect" ar fi `true` — dar relația n-a fost adusă
     * de `eagerLoad()`. Contractul e: cade ÎNCHIS și NU interoghează. Fără ramura aia, apelul
     * ar încărca relația (în producție un N+1 tăcut, fiindcă `preventLazyLoading` e legat de
     * `! isProduction()`) sau ar arunca `LazyLoadingViolationException` în teste.
     */
    public function test_an_unloaded_relation_fails_closed_without_querying(): void
    {
        $this->inTenant(function (): void {
            $proaspata = Invoice::query()->findOrFail($this->mine->getKey());
            $this->assertFalse($proaspata->relationLoaded('order'));

            $this->assertFalse(ActivityVisibility::mayNameSubject($this->rowFor($proaspata), $this->agent));
            $this->assertFalse($proaspata->relationLoaded('order'), 'Decizia nu are voie să încarce relația.');

            // Fără utilizator nu există decizie de vizibilitate, deci nici un „da". Nicio rută
            // nu atinge cazul (totul e sub `auth`), dar atunci nici ramura n-ar avea plasă.
            $incarcata = Invoice::query()->with('order:id,owner_user_id')->findOrFail($this->mine->getKey());
            $this->assertFalse(ActivityVisibility::mayNameSubject($this->rowFor($incarcata), null));
        });
    }

    /**
     * Contractul lui `eagerLoad()`, izolat de mască: aduce `order` odată cu factura. Ucide
     * direct mutația „`morphWith` scos", indiferent ce face `mayNameSubject()` fără el.
     */
    public function test_eager_load_brings_the_order_along_with_the_invoice(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $rand = ActivityLog::query()
                ->with(['auditable' => ActivityVisibility::eagerLoad(...)])
                ->findOrFail($this->rows['mine']);

            $this->assertInstanceOf(Invoice::class, $rand->auditable);
            $this->assertTrue($rand->auditable->relationLoaded('order'));
            $this->assertSame($this->agent->getKey(), $rand->auditable->order->owner_user_id);
        });
    }

    // -------------------------------------------------------------------------------------
    // Ajutoare
    // -------------------------------------------------------------------------------------

    /**
     * Intrările unei liste Inertia, indexate după id-ul rândului de jurnal — testele caută
     * rândul LOR, nu „al N-lea", deci nu se sprijină pe ordinea de sortare.
     *
     * @return array<string, array<string, mixed>>
     */
    private function entriesAs(User $user, string $uri, string $prop): array
    {
        $peId = [];

        $this->actingAs($user)->get($uri)->assertOk()->assertInertia(function ($page) use ($prop, &$peId): void {
            $page->where($prop, function ($randuri) use (&$peId): bool {
                foreach ($randuri as $rand) {
                    $peId[$rand['id']] = $rand;
                }

                return true;
            });
        });

        return $peId;
    }

    /**
     * `TenantContext::run()` leagă tenantul pentru Postgres (RLS) și pentru scope-uri, dar NU
     * pentru rolurile Spatie: acelea sunt per echipă (`config/permission.php`, `teams => true`)
     * și se leagă în `ResolveWorkspace`, adică doar pe calea HTTP. Fără asta, `hasRole()` și
     * `can()` răspund ca pentru un om fără niciun rol — masca ar fi ieșit „nu e Agent" și
     * Gate-ul „n-are `invoices.view`", amândouă din afara subiectului acestor teste. (Găsit
     * exact așa: cele două teste de mai jos au picat prima dată din acest motiv, nu din cod.)
     */
    private function inTenant(Closure $fn): mixed
    {
        return TenantContext::run($this->marlin, function () use ($fn) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->marlin->getKey());

            try {
                return $fn();
            } finally {
                app(PermissionRegistrar::class)->setPermissionsTeamId(null);
            }
        });
    }

    /** Un rând de jurnal pe o factură DEJA încărcată — exact forma pe care o produce `eagerLoad()`. */
    private function rowFor(Invoice $invoice): ActivityLog
    {
        $rand = new ActivityLog(['action' => 'created']);
        $rand->auditable_type = Invoice::class;
        $rand->auditable_id = $invoice->getKey();
        $rand->setRelation('auditable', $invoice);

        return $rand;
    }

    /** @return array<string, mixed>|null */
    private function snapshotOf(Invoice $invoice): ?array
    {
        return ChangedAttributes::snapshot($invoice->getAttributes());
    }

    private function invoiceOn(User $orderOwner, string $orderNumber, string $invoiceNumber): Invoice
    {
        $order = new Order([
            'order_number' => $orderNumber,
            'account_id' => $this->account->getKey(),
            'owner_user_id' => $orderOwner->getKey(),
            'status' => 'confirmed',
            'currency' => 'USD',
            'subtotal' => 100, 'discount_total' => 0, 'shipping_total' => 0, 'grand_total' => 100,
        ]);
        $order->created_by = $this->owner->getKey();
        $order->save();

        $invoice = new Invoice([
            'order_id' => $order->getKey(),
            'invoice_number' => $invoiceNumber,
            'status' => Invoice::STATUS_SENT,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'USD',
            'subtotal' => 100, 'tax_total' => 0, 'total' => 100, 'amount_paid' => 0, 'balance_due' => 100,
        ]);
        $invoice->save();

        return $invoice;
    }

    /** @param  array<string, mixed>|null  $new */
    private function logRow(User $actor, Model $subject, CarbonInterface $at, ?array $new = null): string
    {
        $rand = ActivityLog::query()->create([
            'user_id' => $actor->getKey(),
            'action' => 'created',
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'new_values' => $new,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PestTest/1.0',
        ]);

        // `ActivityLog` e append-only (`App\Concerns\AppendOnly` aruncă pe `updating`):
        // `created_at` se fixează prin `saveQuietly()`, ca în `DashboardTest`.
        $rand->forceFill(['created_at' => $at])->saveQuietly();

        return (string) $rand->getKey();
    }
}
