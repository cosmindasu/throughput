<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan §7.9 — testul care prinde exact clasa de bug livrată de trei ori ca „reparată" în
 * scriptul de bootstrap: privilegii care par corecte la citirea codului și nu sunt.
 *
 * Fiecare aserție de mai jos corespunde unei capcane documentate în §3.3:
 *   - `ALTER DEFAULT PRIVILEGES` fără `FOR ROLE` → aplicația ia `permission denied` pe
 *     fiecare tabelă migrată;
 *   - lipsa lui `GRANT CREATE ON SCHEMA public` → pe PostgreSQL 15+ migratorul nu mai poate
 *     crea tabele (implicitul s-a schimbat);
 *   - rolul aplicației cu `BYPASSRLS` sau proprietar de tabele → politicile devin
 *     decorative, iar suita de izolare trece verde fără să testeze nimic.
 */
class DatabasePrivilegesTest extends TestCase
{
    public function test_the_application_role_has_no_bypassrls(): void
    {
        $role = DB::selectOne('select current_user as name, rolbypassrls from pg_roles where rolname = current_user');

        $this->assertSame('throughput_app', $role->name);
        $this->assertFalse((bool) $role->rolbypassrls);
    }

    public function test_the_application_role_does_not_own_the_tables(): void
    {
        // RLS nu se aplică proprietarului. Dacă `throughput_app` ar fi creat tabelele
        // (de exemplu pentru că testele migrau pe conexiunea implicită), politicile ar fi
        // ignorate în tăcere pentru exact conexiunea pe care contează.
        $owners = DB::table('pg_tables')
            ->where('schemaname', 'public')
            ->pluck('tableowner')
            ->unique();

        $this->assertSame(['throughput_migrator'], $owners->values()->all());
    }

    public function test_the_application_role_can_read_and_write_tables_created_by_the_migrator(): void
    {
        $tables = ['tenants', 'users', 'memberships', 'accounts', 'orders'];

        foreach ($tables as $table) {
            $acl = DB::selectOne('select relacl::text as acl from pg_class where relname = ?', [$table])->acl;

            $this->assertNotNull($acl, "Tabela `{$table}` nu are niciun grant — `ALTER DEFAULT PRIVILEGES FOR ROLE` lipsește?");
            $this->assertStringContainsString('throughput_app=arwd', $acl, "Rolul aplicației nu are SELECT/INSERT/UPDATE/DELETE pe `{$table}`.");
        }
    }

    public function test_the_migrator_role_can_create_tables(): void
    {
        // Pe PostgreSQL 15+, `CREATE` pe schema `public` nu mai e acordat implicit.
        // Fără `GRANT CREATE ON SCHEMA public`, migrațiile cad — dar abia la deploy.
        $migrator = DB::connection('pgsql_migrations');

        $migrator->statement('CREATE TABLE privilege_probe (id int)');
        $exists = $migrator->selectOne("select to_regclass('public.privilege_probe') as t")->t;
        $migrator->statement('DROP TABLE privilege_probe');

        $this->assertSame('privilege_probe', $exists);
    }

    public function test_the_two_connections_use_different_roles(): void
    {
        $this->assertSame('throughput_app', DB::connection('pgsql')->selectOne('select current_user as u')->u);
        $this->assertSame('throughput_migrator', DB::connection('pgsql_migrations')->selectOne('select current_user as u')->u);
    }
}
