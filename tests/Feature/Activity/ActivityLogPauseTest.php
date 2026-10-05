<?php

namespace Tests\Feature\Activity;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Observers\ActivityLogObserver;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use RuntimeException;
use Tests\TestCase;

/**
 * `ActivityLogObserver::withoutRecording()` — pauza folosită de seed-ul demo, care își scrie
 * singur jurnalul cu date istorice.
 *
 * Exista fără NICIO acoperire: o versiune care nu setează flagul, sau una fără `finally`,
 * trecea prin toată suita. Prima ar readuce defectul pentru care a fost scrisă (~80 de rânduri
 * „Created Variant" la minutul rulării seed-ului, chiar în vârful feed-ului de pe dashboard);
 * a doua e mai rea — ar lăsa aplicația FĂRĂ audit după prima eroare la seed, tăcut.
 */
class ActivityLogPauseTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();
        // Observerul doar DISPECERIZEAZĂ evenimentul; scrierea e a unui listener pe coadă
        // (ADR-007), iar testele rulează pe driverul `database` — deci coada se golește
        // explicit, exact ca în `ActivityLogObserverTest`.
        $this->actingAs($this->owner);
    }

    public function test_writes_inside_the_closure_are_not_recorded_but_writes_outside_are(): void
    {
        // Fiecare pas în propriul `TenantContext::run`: drenarea cozii GOLEȘTE contextul de
        // tenant al sesiunii de bază de date (ca să ruleze workerul), iar RLS respinge orice
        // scriere făcută după aceea în aceeași închidere.
        $this->makeProduct('Seeded product', paused: true);
        $this->drainDefaultQueue();

        $this->assertSame(0, $this->logCount(), 'scrierea din interiorul pauzei n-ar trebui să lase urmă');

        $this->makeProduct('Normal product');
        $this->drainDefaultQueue();

        $this->assertSame(1, $this->logCount(), 'instrumentarea trebuie să repornească singură după închidere');
    }

    /**
     * Partea care contează cel mai mult: o excepție în timpul seed-ului NU are voie să lase
     * aplicația fără audit. Fără `finally`, flagul ar rămâne ridicat pentru tot procesul.
     */
    public function test_the_pause_lifts_even_when_the_closure_throws(): void
    {
        try {
            ActivityLogObserver::withoutRecording(function (): void {
                throw new RuntimeException('seed a crăpat');
            });
        } catch (RuntimeException) {
            // așteptat — excepția trebuie să treacă mai departe, nu să fie înghițită
        }

        $this->makeProduct('After the failure');
        $this->drainDefaultQueue();

        $this->assertSame(1, $this->logCount(), 'după o excepție în pauză, auditul trebuie să fie din nou activ');
    }

    /** Valoarea închiderii se întoarce apelantului — seed-ul o folosește ca pe orice apel. */
    public function test_the_closure_result_is_returned(): void
    {
        $this->assertSame('gata', ActivityLogObserver::withoutRecording(fn () => 'gata'));
    }

    private function makeProduct(string $name, bool $paused = false): void
    {
        TenantContext::run($this->marlin, function () use ($name, $paused): void {
            $create = fn () => Product::query()->create(['name' => $name, 'unit_of_measure' => 'each']);

            $paused ? ActivityLogObserver::withoutRecording($create) : $create();
        });
    }

    /** Numărătoarea se face ÎN context: `drainDefaultQueue()` îl golește ca să ruleze workerul. */
    private function logCount(): int
    {
        return TenantContext::run($this->marlin, fn () => ActivityLog::query()->count());
    }

    /**
     * Listener-ul care scrie rândul rulează pe coadă (ADR-007), iar testele folosesc driverul
     * `database` — deci tabela e goală până se golește coada. Același helper ca în
     * `ActivityLogObserverTest`, cu aceeași motivare.
     */
    private function drainDefaultQueue(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);
    }
}
