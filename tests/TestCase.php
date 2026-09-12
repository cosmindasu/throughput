<?php

namespace Tests;

use App\Models\Membership;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\RefreshesTenantDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshesTenantDatabase;

    /**
     * Autentificare în teste, cu sesiunea golită înainte.
     *
     * `AuthenticateSession` e activ global pe rutele autentificate (`bootstrap/app.php`),
     * ca resetarea de parolă să invalideze celelalte sesiuni (FR-PUB-05, BR-PUB-02). Efectul
     * secundar apare doar în teste: middleware-ul compară hash-ul de parolă din sesiune cu
     * cel al utilizatorului curent, iar sesiunea de test persistă între apeluri. Al doilea
     * `actingAs()` din același test moștenește `password_hash_web` al primului utilizator,
     * deci e delogat instantaneu — cererea răspunde `302 → /login`, nu `200`.
     *
     * Nu e un bug de aplicație: în producție fiecare utilizator are sesiunea lui. Dar un test
     * care compară interfața între cele patru roluri (§7.3) face exact asta — mai multe
     * autentificări succesive — deci golirea stă aici, într-un singur loc, nu repetată în
     * fiecare test care se întâmplă să aibă nevoie de ea.
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        $this->flushSession();

        return parent::actingAs($user, $guard);
    }

    /**
     * Golește contextul de tenant așa cum îl vede PostgreSQL.
     *
     * Necesar doar în teste: sub tranzacția de test, un `commit` din cod e eliberarea unui
     * savepoint, deci `app.tenant_id` supraviețuiește. În producție, contextul moare cu
     * cererea. Fără asta, un test care verifică „fără context: 0 rânduri" ar citi, de fapt,
     * contextul lăsat de pasul anterior — și ar trece dintr-un motiv greșit.
     */
    protected function clearDatabaseTenantContext(): void
    {
        DB::statement("select set_config('app.tenant_id', '', true)");
        DB::statement("select set_config('app.user_id', '', true)");

        app()->forgetInstance(TenantScope::CONTAINER_KEY);
        app()->forgetInstance('tenant');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /**
     * Un tenant gata de folosit. `tenants` nu are RLS, deci se creează fără context.
     */
    protected function makeTenant(string $slug, ?string $name = null): Tenant
    {
        $tenant = Tenant::query()->create([
            'name' => $name ?? ucfirst($slug).' Industrial Supply',
            'slug' => $slug,
            'currency' => 'USD',
        ]);

        // Aceleași reguli de rol ca în producție, din același seeder — nu o copie „pentru
        // teste", care s-ar desincroniza de matricea §7.4 la prima modificare.
        app(RoleAndPermissionSeeder::class)->run();

        return $tenant;
    }

    /**
     * Utilizator + membership activ. Membership-ul se scrie ÎN contextul tenantului:
     * politica are doar `USING`, iar PostgreSQL o aplică și ca `WITH CHECK`, deci un INSERT
     * fără context ar fi respins.
     */
    protected function makeMember(Tenant $tenant, string $email, string $role = Permissions::VIEWER, ?User $user = null): User
    {
        $user ??= User::query()->create([
            'name' => str($email)->before('@')->headline()->value(),
            'email' => $email,
            'password' => 'password',
        ]);

        TenantContext::run($tenant, function () use ($tenant, $user, $role): void {
            Membership::query()->create([
                'user_id' => $user->getKey(),
                'status' => Membership::STATUS_ACTIVE,
            ]);

            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
            $user->assignRole($role);
        });

        return $user;
    }
}
