<?php

use App\Jobs\System\PruneExpiredExportsJob;
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

// Plan §7.2 (lista joburilor de sistem) — curățarea exporturilor expirate (FR-GDPR-01,
// specs.md §20.5, retenție de `throughput.limits.export_retention_days` zile). Fără `when()`:
// spre deosebire de resetul demo, jobul e util oricând există exporturi (demo sau nu) — el
// însuși iterează tenanții (ADR-014, pct. 4), scheduler-ul doar dispecerizează.
Schedule::job(new PruneExpiredExportsJob, 'default')->daily();

// FR-STOCK-01, specs.md §10.4 — reconcilierea săptămânală a stocului: recalculează
// `inventory_levels.on_hand` din `stock_movements` și raportează divergențele, fără să
// corecteze (§10.1, ADR-004). Comandă de consolă, nu un job — iterează tenanții ea
// însăși (`App\Console\Commands\StockReconcile`, ADR-014 pct. 4) și scrie raportul pe
// ieșirea standard a scheduler-ului, care ajunge în logul de operare (§25.2).
Schedule::command('stock:reconcile')->weekly();
