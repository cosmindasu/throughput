<?php

// Import CSV în 4 pași (Upload → Mapare → Probă uscată → Commit → Raport) — specs.md §14,
// plan §10. Inclus din routes/web.php, în grupul cu workspace.
//
// `/imports/template/{resourceType}` stă ÎNAINTE de `/imports/{import}` — la fel ca
// `AccountController::export()` (routes/web/accounts.php): altfel „template" ar risca să
// fie interpretat ca segmentul `{import}`. `{resourceType}` restricționat la nivel de rută,
// ca `AccountLookupController`/`routes/web/bulk.php` — orice altă valoare e 404 înainte să
// ajungă la `ImportableResources::resolve()`.

use App\Http\Controllers\Web\Imports\ImportController;
use App\Support\Imports\ImportableResources;
use Illuminate\Support\Facades\Route;

Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
Route::get('/imports/create', [ImportController::class, 'create'])->name('imports.create');
Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');

Route::get('/imports/template/{resourceType}', [ImportController::class, 'template'])
    ->whereIn('resourceType', ImportableResources::types())
    ->name('imports.template');

Route::get('/imports/{import}', [ImportController::class, 'show'])->name('imports.show');
Route::post('/imports/{import}/mapping', [ImportController::class, 'updateMapping'])->name('imports.mapping');
Route::post('/imports/{import}/dry-run', [ImportController::class, 'runDryRun'])->name('imports.dry-run');
Route::post('/imports/{import}/commit', [ImportController::class, 'commit'])->name('imports.commit');
Route::post('/imports/{import}/cancel', [ImportController::class, 'cancel'])->name('imports.cancel');
Route::get('/imports/{import}/errors', [ImportController::class, 'downloadErrors'])->name('imports.errors');
