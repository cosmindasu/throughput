<?php

namespace Tests\Feature\Tenancy;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ScansPhpSource;
use Tests\TestCase;

/**
 * TEST-04 (audit 2026-09-23, `docs/reviews/2026-09-23_audit/11-teste.md`) — completează
 * exact perechea lăsată goală de `IsolationTest::test_every_table_with_a_tenant_id_column_
 * has_row_level_security_enabled()`. Acela verifică STRATUL 2 (RLS, `pg_policies`/`pg_class`),
 * exhaustiv, pentru fiecare TABELĂ cu `tenant_id`. Stratul 1 (Eloquent, global scope) era
 * verificat înainte doar pe `Account`
 * (`IsolationTest::test_eloquent_global_scope_hides_another_tenants_rows`) — un model NOU cu
 * `tenant_id` care uită `BelongsToTenant`/`TenantScope` ar fi trecut testul de tabelă (RLS
 * tot îl protejează la nivel SQL), dar orice cod care presupune filtrare implicită prin
 * Eloquent (rapoarte, agregate, liste) ar vedea rânduri din toți tenanții pe conexiuni fără
 * RLS forțat — de pildă un job de sistem care rulează accidental fără `TenantContext::run()`
 * (ADR-003: stratul 1 e cel care dă eroarea inteligibilă, `TenantContextMissingException`;
 * fără el, RLS eșuează închis — tăcut, zero rânduri, nu o eroare care spune de ce).
 *
 * Sursa de adevăr pentru „ce tabelă are `tenant_id`" e schema REALĂ
 * (`information_schema.columns`), la fel ca `IsolationTest` — nu o listă din memorie, care
 * s-ar putea desincroniza de prima migrație nouă. De aceea acest test extinde
 * `Tests\TestCase` (are nevoie de bază de date), spre deosebire de `ArchitectureTest`.
 *
 * Verificat prin `App\Models\Scopes\TenantScope::hasGlobalScope()` PE CLASĂ — nu prin grep
 * pe `use BelongsToTenant` în sursă: trait-ul e cel care înregistrează scope-ul
 * (`BelongsToTenant::bootBelongsToTenant()`), dar un `grep` „folosește trait-ul" ar trece și
 * pe un model care îl importă fără să-l pună efectiv în `use`, la fel cum docblock-ul lui
 * `test_url_and_api_exposed_models_use_ulid_primary_keys()` din `ArchitectureTest` explică
 * pentru `HasUlids`. Reflecția pe comportamentul REAL, nu pe text sursă.
 */
class ModelTenantScopeCoverageTest extends TestCase
{
    use ScansPhpSource;

    public function test_every_model_whose_table_has_a_tenant_id_column_applies_the_tenant_scope(): void
    {
        // Aceeași sursă de adevăr ca `IsolationTest` — tabelele de framework (Spatie
        // Permission) au `tenant_id` (mapare multi-tenant pe roluri/permisiuni), dar nu
        // sunt modele Eloquent ale acestui proiect, deci n-au cum să aplice `TenantScope`.
        $tenantScopedTables = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_name', 'tenant_id')
            ->pluck('table_name')
            ->reject(fn (string $table) => in_array($table, ['roles', 'model_has_roles', 'model_has_permissions'], true))
            ->values()
            ->all();

        // Gardă anti-„vacuous truth" (același prag ca `IsolationTest`): dacă scanarea
        // schemei s-ar rupe, bucla de mai jos ar trece verde fără să verifice nimic.
        $this->assertGreaterThan(25, count($tenantScopedTables), 'Schema pare incompletă — verifică migrațiile.');

        $offenders = [];
        $checked = 0;

        foreach ($this->modelClasses() as $class) {
            if (in_array($class, $this->modelsExemptFromTenantScope(), true)) {
                continue;
            }

            $model = new $class;

            if (! in_array($model->getTable(), $tenantScopedTables, true)) {
                continue;
            }

            $checked++;

            if (! $class::hasGlobalScope(TenantScope::class)) {
                $offenders[] = sprintf('%s (tabelă `%s`)', $class, $model->getTable());
            }
        }

        // A doua gardă anti-„vacuous truth", simetrică: dacă maparea model↔tabelă s-ar rupe
        // (nume de tabelă schimbat, model mutat), bucla de mai sus ar verifica zero modele.
        $this->assertGreaterThan(25, $checked, 'Nicio potrivire tabelă↔model n-a fost verificată — verifică `getTable()` pe modelele din app/Models.');

        $this->assertSame(
            [],
            $offenders,
            "Model(e) a căror tabelă are `tenant_id`, dar care NU aplică TenantScope — orice\n"
            ."interogare Eloquent pe conexiuni fără RLS forțat (job de sistem fără\n"
            ."TenantContext::run(), rapoarte, agregate) ar vedea rânduri din TOȚI tenanții:\n"
            .implode("\n", $offenders),
        );
    }

    /**
     * Listă ALBĂ, motivată individual — verificat direct (interogare pe schema reală de mai
     * sus), nu presupus: tabelele lor n-au coloana `tenant_id`, deci bucla principală le-ar
     * sări oricum — documentate aici explicit, ca excepția să fie citibilă fără să rulezi
     * testul, la fel ca `modelsNeverExposedThroughAUrl()` din `ArchitectureTest`.
     *
     * @return list<class-string>
     */
    private function modelsExemptFromTenantScope(): array
    {
        return [
            // `Tenant` E rândul de tenant — n-are sens ca tabela lui să aibă propriul
            // `tenant_id`. Global scope-ul l-ar izola de EL ÎNSUȘI.
            Tenant::class,
            // Partajat între tenanți prin `Membership` (ADR-014): un utilizator membru în
            // două organizații e UN singur rând `users`, nu unul per tenant.
            User::class,
        ];
    }
}
