<?php

use App\Jobs\System\DispatchScheduledReportsJob;
use App\Jobs\System\FailStuckBulkOperationsJob;
use App\Jobs\System\FailStuckImportsJob;
use App\Jobs\System\PruneExpiredExportsJob;
use App\Jobs\System\PruneExpiredImportFilesJob;
use App\Jobs\System\PruneSentEmailsJob;
use App\Jobs\System\ResetDemoDataJob;
use App\Support\DemoMode;
use Carbon\CarbonImmutable;
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

// §13.2, pct. 8 — job_batches nu trebuie să crească nemărginit (comandă nativă Laravel,
// fără parametri: implicit șterge batch-urile terminate de peste 24h). Fără `when()`, ca la
// `PruneExpiredExportsJob`: operațiile în masă există independent de DEMO_MODE.
Schedule::command('queue:prune-batches')->daily();

// §13.2 (code review, fix operațional) — plasă de siguranță pentru fereastra dintre
// `Bus::batch()->dispatch()` și scrierea `batch_id` (`PlanBulkOperationJob`, tries=1
// deliberat, deci fără reîncercare proprie): la 5 minute, nu zilnic ca restul acestei
// liste, fiindcă utilizatorul se uită ACTIV la o bară de progres blocată — pragul de
// „blocat" e 15 minute (`bulk_stuck_operation_minutes`), iar 5 minute de verificare ține
// întârzierea maximă vizibilă sub o treime din prag, cu o interogare îngustă, indexată,
// per tenant — cost neglijabil pe bugetul de memorie al scheduler-ului (ADR-017).
Schedule::job(new FailStuckBulkOperationsJob, 'default')->everyFiveMinutes();

// Rapoarte și livrare programată (specs.md §16.2 pct. 1, plan §10, lotul K din Faza 4).
// ORAR, nu mai des: specificația cere explicit „verifică ce report_definitions sunt
// scadente ÎN ORA CURENTĂ" — `schedule_time` se compară doar pe componenta de oră
// (`App\Support\Reports\ReportSchedule`), deci un tick mai des n-ar avansa nimic, doar ar
// interoga fiecare tenant de mai multe ori pe oră. Jobul e de SISTEM: iterează el însuși
// tenanții (ADR-014 pct. 4) — scheduler-ul doar dispecerizează, ca la restul listei de mai
// sus. `ShouldBeUnique` pe job (vezi docblock-ul clasei) previne dublarea dacă `schedule:run`
// pornește acest tick de două ori (redeploy, tick suprapus cu un run anterior lung).
//
// Fix P2 (review) — ora de referință se CAPTUREAZĂ AICI, la tick (`new DispatchScheduledReportsJob(...)`
// se construiește la fiecare invocare a lui `schedule:run`, deci practic în același moment
// în care cron-ul de sistem îl declanșează), NU în `handle()`. Proiectul are UN SINGUR
// worker de coadă (ADR-017): dacă jobul stă în coadă și se execută cu întârziere peste
// granița orei, `CarbonImmutable::now()` apelat înăuntrul jobului ar vedea ora GREȘITĂ, iar
// un raport scadent la ora de tick ar fi sărit tăcut. Scalar în constructor (§6.3).
Schedule::job(new DispatchScheduledReportsJob(CarbonImmutable::now('UTC')->startOfHour()->toIso8601String()), 'default')->hourly();

// §22.3 (lotul L din Faza 4) — retenția jurnalului „Sent Emails". Fără `when()`, ca la
// `PruneExpiredExportsJob`: jurnalul există doar cât timp DEMO_MODE=true, dar dacă demo-ul
// se oprește, rândurile deja scrise tot trebuie să expire. Cât timp resetul de noapte merge,
// el golește oricum tabela — jobul ăsta e plasa de siguranță pentru un reset picat
// (`ResetDemoDataJob` are `tries=1`), nu mecanismul principal.
Schedule::job(new PruneSentEmailsJob, 'default')->daily();

// §14/§22.5 (lotul J din Faza 4, fix din review) — plasa de siguranță a limitei „un import
// activ per tenant". Fără ea, UN import abandonat sau UN proces ucis de OOM blochează orice
// import viitor al tenantului, definitiv. La 5 minute, ca `FailStuckBulkOperationsJob` și din
// același motiv: utilizatorul se uită ACTIV la o bară de progres. Jobul are două praguri
// (`import_stuck_minutes` pentru lanțul rupt, `import_abandoned_hours` pentru fișierul uitat),
// fiindcă maparea coloanelor e activitate umană, cu pauze — vezi comentariile cheilor.
Schedule::job(new FailStuckImportsJob, 'default')->everyFiveMinutes();

// §20.5 (lotul J din Faza 4, fix din review) — retenția FIȘIERULUI încărcat, care conține și
// coloanele nemapate, adică date care n-au ajuns niciodată în aplicație. Nimic nu le ștergea:
// nici o retenție proprie, nici `demo:reset` (care curăța doar `exports/`). Ca
// `PruneExpiredExportsJob`, fără `when()`. `import_rows.raw_data` rămâne neatins — BR-IMP-01
// îl cere pentru raportul reimportabil.
Schedule::job(new PruneExpiredImportFilesJob, 'default')->daily();
