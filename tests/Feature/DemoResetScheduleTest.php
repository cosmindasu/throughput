<?php

namespace Tests\Feature;

use App\Jobs\System\ResetDemoDataJob;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

/**
 * FR-DEMO-03 — resetul zilnic: programat la cron-ul configurat, dispecerizat pe Horizon
 * (ADR-017), oprit în afara demo-ului.
 *
 * `demo:reset` însuși nu rulează aici — e `migrate:fresh` plus seed-ul complet. Se verifică
 * intrarea din scheduler și contractul jobului; comanda a fost măsurată separat, pe bază
 * dedicată (heap, RSS, durată), iar cifrele stau în ADR.
 */
class DemoResetScheduleTest extends TestCase
{
    /**
     * `demo:reset` rulează pe imaginea `production` (`composer install --no-dev`), iar seed-ul
     * trece prin `fake()` și prin `definition()` pe factories. Cu Faker în `require-dev`, jobul
     * ar pica noaptea cu „Call to undefined function fake()", invizibil în orice test local,
     * unde dependențele de dev sunt mereu instalate.
     */
    public function test_the_demo_seed_generator_is_a_production_dependency(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('fakerphp/faker', $composer['require']);
        $this->assertArrayNotHasKey('fakerphp/faker', $composer['require-dev']);
    }

    public function test_the_reset_is_scheduled_at_the_configured_cron(): void
    {
        $event = $this->resetEvent();

        $this->assertSame(config('throughput.demo.reset_cron'), $event->expression);
        $this->assertSame('0 3 * * *', $event->expression, 'Implicitul din specs.md §22.1: 03:00 UTC.');
    }

    public function test_the_schedule_entry_fires_only_in_demo_mode(): void
    {
        $event = $this->resetEvent();

        config(['throughput.demo.mode' => true]);
        $this->assertTrue($event->filtersPass($this->app));

        config(['throughput.demo.mode' => false]);
        $this->assertFalse($event->filtersPass($this->app));
    }

    public function test_the_job_runs_demo_reset(): void
    {
        config(['throughput.demo.mode' => true]);

        Artisan::shouldReceive('call')->once()->with('demo:reset')->andReturn(0);

        (new ResetDemoDataJob)->handle();
    }

    public function test_a_failed_reset_fails_the_job_loudly(): void
    {
        config(['throughput.demo.mode' => true]);

        Artisan::shouldReceive('call')->once()->with('demo:reset')->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('SQLSTATE[08006] connection refused');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLSTATE[08006]');

        (new ResetDemoDataJob)->handle();
    }

    public function test_outside_demo_mode_the_job_touches_nothing_even_if_already_queued(): void
    {
        config(['throughput.demo.mode' => false]);

        Artisan::shouldReceive('call')->never();

        (new ResetDemoDataJob)->handle();
    }

    public function test_the_queue_never_considers_a_running_reset_abandoned(): void
    {
        $job = new ResetDemoDataJob;

        // Cu `retry_after` sub timeout-ul jobului, Redis ar pune resetul înapoi în coadă cât
        // încă rulează — exact scenariul în care două `migrate:fresh` se ating.
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.redis.retry_after'));
        $this->assertSame(1, $job->tries);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
    }

    private function resetEvent(): Event
    {
        // Rutele de consolă (și deci scheduler-ul) se încarcă abia la pornirea kernelului de
        // consolă, pe care un test HTTP nu-l pornește singur.
        $this->app->make(Kernel::class)->bootstrap();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => $event->description === ResetDemoDataJob::class);

        $this->assertNotNull($event, 'Intrarea de scheduler pentru resetul demo lipsește din routes/console.php.');

        return $event;
    }
}
