<?php

use App\Jobs\System\ResetDemoDataJob;
use App\Support\DemoMode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FR-DEMO-03 (specs.md §22.1) — resetul zilnic al demo-ului public, activ de la publicare
// (plan §8). Scheduler-ul doar DISPECERIZEAZĂ; resetul rulează pe Horizon, fiindcă în
// containerul `scheduler` (128m) nu încape — ADR-017, cu măsurătorile. `when()` se evaluează
// la fiecare trecere a scheduler-ului, deci DEMO_MODE=false oprește resetul fără alt deploy.
Schedule::job(new ResetDemoDataJob, 'default')
    ->cron(config('throughput.demo.reset_cron'))
    ->when(fn (): bool => DemoMode::enabled());
