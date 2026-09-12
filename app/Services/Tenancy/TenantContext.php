<?php

namespace App\Services\Tenancy;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * ADR-014, pct. 3 — SINGURA poartă care deschide tranzacția și setează contextul.
 * Middleware-ul HTTP, middleware-ul de job și comenzile de consolă o apelează identic.
 * Trei locuri de setat context = trei feluri de a greși.
 *
 * `set_config(..., ?, true)`, NU `SET LOCAL app.tenant_id = ?` (ADR-014, pct. 1):
 * `SET` nu acceptă parametri legați, iar `DB::statement()` face prepare() + execute().
 * Forma din planul inițial producea `SQLSTATE[42601]: syntax error at or near "$1"` —
 * mecanismul pe care stă tot ADR-003 nu pornea. Al treilea argument `true` scopează
 * valoarea tranzacției, exact ca `SET LOCAL`; cu `false` supraviețuiește commit-ului
 * și scurge tenantul în cererea următoare de pe aceeași conexiune.
 */
class TenantContext
{
    /**
     * Deschide tranzacția cererii și setează ce se știe deja; tenantul vine mai târziu,
     * din ResolveWorkspace, în ACEEAȘI tranzacție (deci fără tranzacție imbricată).
     */
    public static function openFor(?string $userId, Closure $fn): mixed
    {
        return DB::transaction(function () use ($userId, $fn) {
            if ($userId !== null) {
                self::setUser($userId);
            }

            return self::preservingContainerBinding($fn);
        });
    }

    /**
     * `app.user_id` — a doua variabilă de sesiune (ADR-014, pct. 2). Se setează la
     * autentificare, înainte să se știe workspace-ul, și e singurul lucru care face
     * comutatorul de workspace posibil sub RLS.
     */
    public static function setUser(string $userId): void
    {
        self::assertInsideTransaction('app.user_id');

        DB::statement("select set_config('app.user_id', ?, true)", [$userId]);
    }

    /**
     * Se cheamă ÎNTOTDEAUNA din interiorul lui `openFor()` sau `run()` — ele sunt cele
     * care readuc legătura din container la ce era, când tranzacția se închide.
     *
     * Motivul e o asimetrie găsită la verificarea pe bază reală, nu o precauție teoretică:
     * PostgreSQL uită `app.tenant_id` la commit (al treilea argument `true`), dar
     * containerul nu uită nimic de la sine. Un `setTenant()` chemat pe cont propriu lasă
     * stratul 1 (global scope) să creadă că mai există un tenant după ce stratul 2 (RLS)
     * l-a uitat deja — adică exact dezacordul între straturi pe care ADR-003 îl evită.
     */
    public static function setTenant(string $tenantId): void
    {
        self::assertInsideTransaction('app.tenant_id');

        DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);

        app()->instance(TenantScope::CONTAINER_KEY, $tenantId);
    }

    /**
     * `set_config(..., true)` în afara unei tranzacții e un no-op tăcut: instrucțiunea e
     * propria ei tranzacție, deci valoarea moare imediat. Stratul 1 ar rămâne legat de
     * tenant, stratul 2 nu — adică scoparea ar părea să funcționeze exact până la primul
     * `DB::table()`. Mai bine o excepție aici decât o interogare nescopată mai încolo.
     */
    private static function assertInsideTransaction(string $setting): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                "{$setting} se setează doar într-o tranzacție deschisă: cheamă TenantContext::openFor() sau ::run() (ADR-014)."
            );
        }
    }

    /**
     * Pentru joburi de tenant, joburi de sistem și comenzi: o tranzacție + un context.
     *
     * Contextul anterior se restaurează la ieșire, pentru că un job de sistem iterează
     * tenanții în același proces: fără restaurare, ultimul tenant ar rămâne legat în
     * container după bucla de seed și ar scopa tăcut orice interogare de după.
     */
    public static function run(Tenant|string $tenant, Closure $fn): mixed
    {
        $id = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return DB::transaction(fn () => self::preservingContainerBinding(function () use ($id, $fn) {
            self::setTenant($id);

            return $fn();
        }));
    }

    /**
     * Ține legătura `tenant.id` din container sincronă cu ce face PostgreSQL la commit.
     *
     * Contează pentru joburile de sistem, care iterează tenanții în același proces: fără
     * restaurare, ultimul tenant din buclă ar rămâne legat și ar scopa tăcut orice
     * interogare de după seed — un scope greșit e mai greu de observat decât unul lipsă.
     */
    private static function preservingContainerBinding(Closure $fn): mixed
    {
        $previous = TenantScope::currentTenantId();

        try {
            return $fn();
        } finally {
            if ($previous === null) {
                app()->forgetInstance(TenantScope::CONTAINER_KEY);
            } else {
                app()->instance(TenantScope::CONTAINER_KEY, $previous);
            }
        }
    }

    /**
     * Ce vede efectiv PostgreSQL. Folosit de teste și de diagnostic — nu de codul de
     * producție, care citește contextul din container prin TenantScope.
     */
    public static function currentDatabaseSetting(string $name): ?string
    {
        $value = DB::selectOne('select current_setting(?, true) as value', ["app.{$name}"])->value;

        return $value === '' ? null : $value;
    }
}
