<?php

namespace App\Jobs\System;

use App\Support\DemoMode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * FR-DEMO-03 (specs.md §22.1) — resetul zilnic al demo-ului public.
 *
 * Rulează ca job pe Horizon, nu direct în containerul `scheduler` — ADR-017. Pe scurt, pe
 * cifre măsurate: `demo:reset` urcă la ~80 MB heap / ~90 MB RSS și ~60-70 s. În `scheduler`
 * (128m) ar fi rulat lângă `schedule:work` și lângă `schedule:run`-ul pornit la fiecare minut,
 * adică ~150-160 MB, peste plafon. În `horizon` (384m, deja bugetat) încape fără să schimbe
 * vreun plafon, iar scheduler-ul rămâne ce spune planul: doar dispecerizează.
 *
 * Job de SISTEM (`.ai/rules/tenancy.md`): fără tenant. `demo:reset` iterează singur tenanții,
 * fiecare în contextul lui (DemoDatasetSeeder).
 */
class ResetDemoDataJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Un reset eșuat nu se reia automat: a doua rulare, peste o schemă ștearsă pe jumătate,
     * ar îngropa eroarea reală sub una nouă.
     */
    public int $tries = 1;

    /**
     * Mult peste durata măsurată, cu marjă pentru un VPS încărcat. Trebuie să rămână SUB
     * `retry_after` al conexiunii Redis: altfel coada consideră jobul abandonat cât încă
     * rulează și îl pune înapoi — verificat de DemoResetScheduleTest.
     */
    public int $timeout = 600;

    public bool $failOnTimeout = true;

    /** Blocajul de unicitate ține cât un reset, ca două dispecerizări să nu se suprapună. */
    public int $uniqueFor = 900;

    public function handle(): void
    {
        // Reverificat la execuție, nu doar la dispecerizare: între cele două, DEMO_MODE poate fi
        // oprit, iar un `migrate:fresh` pe un mediu care nu mai e demo e ireversibil.
        if (! DemoMode::enabled()) {
            return;
        }

        $exitCode = Artisan::call('demo:reset');

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "demo:reset a ieșit cu codul {$exitCode}: ".Str::limit(trim(Artisan::output()), 2000)
            );
        }
    }
}
