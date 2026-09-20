<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Jobs\RecordVisibleAccountsJob;
use Tests\TestCase;

/**
 * Regresie pentru capcana descrisă în `Tests\TestCase::setUp()`: `queue:work
 * --stop-when-empty` se oprea după PRIMUL job când memoria procesului PHPUnit depășea
 * plafonul implicit de 128 MB al workerului.
 *
 * Testul nu verifică o opțiune de configurare, ci COMPORTAMENTUL sub condiția care
 * declanșa defectul: umflă deliberat memoria procesului peste plafon, apoi cere o drenare
 * și pretinde coada goală. Fără reparația din `TestCase`, aici rămâne un job în coadă —
 * exact forma în care s-au manifestat cele 15 eșecuri dependente de ordine din Faza 5.
 */
class QueueDrainMemoryLimitTest extends TestCase
{
    public function test_draining_the_queue_empties_it_even_when_the_phpunit_process_is_over_the_workers_memory_limit(): void
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->makeMember($marlin, 'demo.owner@throughput.dev');

        RecordVisibleAccountsJob::dispatch($marlin->getKey());
        RecordVisibleAccountsJob::dispatch($cascade->getKey());

        $this->assertSame(2, DB::table('jobs')->count());

        // O SINGURĂ alocare uriașă (peste pragul de 2 MB al Zend MM, deci mapată separat):
        // `memory_get_usage(true)` urcă imediat și coboară la fel de prompt la `unset()`,
        // deci testul nu lasă în urmă un vârf de memorie pentru restul suitei. Un milion de
        // șiruri mici ar fi umflat heap-ul definitiv.
        $ballast = str_repeat('x', 200 * 1024 * 1024);

        $this->assertGreaterThan(128, memory_get_usage(true) / 1048576, 'Balastul nu a urcat procesul peste plafonul workerului.');

        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', ['--stop-when-empty' => true, '--no-interaction' => true]);

        unset($ballast);

        $this->assertSame(0, DB::table('jobs')->count(), 'Drenarea s-a oprit după primul job — plafonul de memorie al workerului e din nou activ în teste.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    /**
     * Balastul de mai sus nu trebuie să rămână în urmă: restul suitei rulează în același
     * proces, iar proiectul are un buget de memorie strâns (`.ai/rules/project.md`).
     */
    public function test_the_ballast_is_released_back_to_the_process(): void
    {
        $before = memory_get_usage(true);

        $ballast = str_repeat('x', 200 * 1024 * 1024);
        unset($ballast);

        $this->assertLessThan($before + 64 * 1024 * 1024, memory_get_usage(true));
    }
}
