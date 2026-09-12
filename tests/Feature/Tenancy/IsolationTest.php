<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FR-TEST-01 — prioritatea de testare a proiectului (§24.2).
 *
 * O scurgere cross-tenant e singura clasă de defect fără gradație: fie absentă, fie
 * fatală (ADR-003). Testele de mai jos verifică AMBELE straturi separat, pentru că fiecare
 * acoperă punctele oarbe ale celuilalt — un test care le confundă ar trece verde cu unul
 * singur funcțional.
 */
class IsolationTest extends TestCase
{
    private Tenant $marlin;

    private Tenant $cascade;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->user = $this->makeMember($this->marlin, 'owner@marlin.test');
    }

    public function test_every_table_with_a_tenant_id_column_has_row_level_security_enabled(): void
    {
        // Verificarea structurală care prinde regresia realistă: cineva adaugă o migrație
        // nouă în Faza 3 și uită `$this->enableRls()`. Nu s-ar vedea la review și nu s-ar
        // vedea nici în testele funcționale — doar tabela aia ar rămâne fără plasa a doua.
        $tenantScoped = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_name', 'tenant_id')
            ->pluck('table_name')
            ->reject(fn (string $table) => in_array($table, ['roles', 'model_has_roles', 'model_has_permissions'], true))
            ->values();

        $this->assertGreaterThan(25, $tenantScoped->count(), 'Schema pare incompletă — verifică migrațiile.');

        foreach ($tenantScoped as $table) {
            $class = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);
            $policies = DB::table('pg_policies')->where('tablename', $table)->count();

            $this->assertTrue((bool) $class->relrowsecurity, "Tabela `{$table}` are `tenant_id`, dar nu are RLS activ.");
            $this->assertSame(1, $policies, "Tabela `{$table}` ar trebui să aibă exact o politică RLS.");
        }
    }

    public function test_eloquent_global_scope_hides_another_tenants_rows(): void
    {
        $this->createAccount($this->marlin, 'Marlin Industrial Fasteners LLC');
        $this->createAccount($this->cascade, 'Cascade Hydraulics Group Inc.');

        TenantContext::run($this->marlin, function (): void {
            $this->assertSame(1, Account::query()->count());
            $this->assertSame('Marlin Industrial Fasteners LLC', Account::query()->value('name'));
        });

        TenantContext::run($this->cascade, function (): void {
            $this->assertSame(1, Account::query()->count());
            $this->assertSame('Cascade Hydraulics Group Inc.', Account::query()->value('name'));
        });
    }

    public function test_row_level_security_hides_another_tenants_rows_from_raw_queries(): void
    {
        // Stratul 2, testat unde stratul 1 nu ajunge: `DB::table()` ocolește deliberat
        // Eloquent, deci global scope-ul nu se aplică. Dacă RLS ar fi inert (rol greșit,
        // politică lipsă, `FORCE` neînțeles), aici s-ar vedea rândul celuilalt tenant.
        $this->createAccount($this->marlin, 'Marlin Industrial Fasteners LLC');
        $this->createAccount($this->cascade, 'Cascade Hydraulics Group Inc.');

        TenantContext::run($this->marlin, function (): void {
            $names = DB::table('accounts')->pluck('name');

            $this->assertCount(1, $names);
            $this->assertSame('Marlin Industrial Fasteners LLC', $names->first());
        });
    }

    public function test_without_any_context_nothing_is_visible_and_nothing_can_be_written(): void
    {
        $this->createAccount($this->marlin, 'Marlin Industrial Fasteners LLC');

        $this->clearDatabaseTenantContext();

        // Cade închis, nu deschis: zero rânduri, nu toate rândurile.
        $this->assertSame(0, DB::table('accounts')->count());

        // Și nu doar la citire: politica are doar `USING`, iar PostgreSQL o folosește ȘI
        // ca `WITH CHECK`. De aici regula că până și seed-ul are nevoie de context.
        $this->expectExceptionMessageMatches('/row-level security/');

        DB::table('accounts')->insert([
            'id' => (string) str()->ulid(),
            'tenant_id' => $this->marlin->getKey(),
            'name' => 'Scris fără context',
            'created_by' => $this->user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_related_records_are_isolated_too(): void
    {
        $marlinAccount = $this->createAccount($this->marlin, 'Marlin Industrial Fasteners LLC');
        $cascadeAccount = $this->createAccount($this->cascade, 'Cascade Hydraulics Group Inc.');

        TenantContext::run($this->marlin, function () use ($marlinAccount): void {
            $contact = new Contact([
                'account_id' => $marlinAccount->getKey(),
                'first_name' => 'Dana',
                'last_name' => 'Whitfield',
                'email' => 'dana@marlin.test',
            ]);
            $contact->created_by = $this->user->getKey();
            $contact->save();
        });

        TenantContext::run($this->cascade, function () use ($cascadeAccount): void {
            // Contul celuilalt tenant nu e nici măcar adresabil după cheie — exact
            // scenariul OWASP API1:2023 (BOLA), oprit în bază, nu doar în controller.
            $this->assertNull(Account::query()->find($cascadeAccount->getKey().'X'));
            $this->assertSame(0, Contact::query()->count());
        });
    }

    private function createAccount(Tenant $tenant, string $name): Account
    {
        return TenantContext::run($tenant, function () use ($name): Account {
            $account = new Account(['name' => $name, 'status' => Account::STATUS_ACTIVE]);
            $account->created_by = $this->user->getKey();
            $account->save();

            return $account;
        });
    }
}
