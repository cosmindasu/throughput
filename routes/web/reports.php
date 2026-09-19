<?php

// Rapoarte și livrare programată — specs.md §16, plan §10. Inclus din routes/web.php, în
// grupul cu workspace.

use App\Http\Controllers\Web\Reports\ReportController;
use Illuminate\Support\Facades\Route;

// `/reports/create` ÎNAINTEA lui `/reports/{report}` — aceeași convenție ca
// routes/web/products.php (segment special declarat primul).
Route::get('/reports/create', [ReportController::class, 'create'])->name('reports.create');
Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
Route::post('/reports', [ReportController::class, 'store'])->name('reports.store');
Route::get('/reports/{report}/edit', [ReportController::class, 'edit'])->name('reports.edit');
Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
Route::put('/reports/{report}', [ReportController::class, 'update'])->name('reports.update');
Route::delete('/reports/{report}', [ReportController::class, 'destroy'])->name('reports.destroy');

Route::post('/reports/{report}/run', [ReportController::class, 'runNow'])->name('reports.run');
Route::get('/reports/{report}/runs/{run}/download', [ReportController::class, 'download'])->name('reports.runs.download');
