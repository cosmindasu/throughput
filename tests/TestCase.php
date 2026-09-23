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
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\RefreshesTenantDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshesTenantDatabase;

    /**
     * Scoate plafonul de memorie al workerului de coadă — DOAR în teste.
     *
     * `queue:work` are implicit `--memory=128`, iar `Worker::stopIfNecessary()` verifică
     * plafonul ÎNAINTEA condiției „coada e goală":
     *
     *     $this->memoryExceeded($options->memory)     => EXIT_MEMORY_LIMIT
     *     $options->stopWhenEmpty && is_null($job)    => EXIT_SUCCESS
     *
     * Măsurătoarea e `memory_get_usage(true)` — memoria REALĂ a procesului, nu a jobului.
     * Într-un worker de producție asta e exact ce trebuie: procesul se reciclează înainte
     * să crească necontrolat. În suită însă, „procesul" e PHPUnit, care acumulează memorie
     * de la toate testele de dinainte și trece de 128 MB pe la jumătatea rulării. Din acel
     * punct, fiecare `queue:work --stop-when-empty` procesează UN SINGUR job și iese —
     * tăcut, cu cod de ieșire 0, ca și cum coada s-ar fi golit.
     *
     * Simptomul e derutant fiindcă depinde de ORDINE, nu de test: 15 teste din 5 fișiere
     * treceau în izolare și în oricare jumătate a suitei, și cădeau doar în suita întreagă,
     * cu mesaje care păreau fără legătură („un job în plus în coadă", „eticheta a rămas
     * `label_pending`", „grupul a rămas `running`"). Toate însemnau același lucru: drenarea
     * s-a oprit după primul job. Reprodus în 3 secunde alocând 200 MB înaintea fișierului
     * de teste.
     *
     * `0` dezactivează verificarea (`Worker::memoryExceeded()` cere plafon > 0). Aici, și
     * nu la cele ~45 de apeluri `queue:work` din suită, ca un test nou să nu reintroducă
     * capcana pur și simplu uitând opțiunea. Vezi `.ai/rules/tenancy.md`.
     *
     * `Http::preventStrayRequests()` (TEST-06, audit 2026-09-23): ADR-013 interzice apelurile
     * externe sincrone în cererea HTTP ca „regulă absolută", dar o încălcare nu dă eroare, dă
     * tranzacții lungi vizibile abia sub concurență. Fără gardă, un apel nou fără
     * `Http::fake()` ar pleca din CI spre un domeniu real, sau ar agăța pe un DNS mort.
     * Acum orice cerere nefakuită aruncă imediat. `Http::fake()` explicit din testele de
     * curierat rămâne compatibil.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->app->resolving(
            WorkCommand::class,
            static fn (WorkCommand $command) => $command->getDefinition()->getOption('memory')->setDefault(0),
        );
    }

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
