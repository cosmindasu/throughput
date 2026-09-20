<?php

// FR-TEN-05 — vederea „Unassigned" (§6.4.1). Inclus din routes/web.php, în grupul cu
// workspace. Owner/Manager (`unassigned.view`, verificat în controller).

use App\Http\Controllers\Web\Members\UnassignedController;
use App\Support\Bulk\EnsureBulkConcurrencyLimit;
use Illuminate\Support\Facades\Route;

Route::get('/unassigned', [UnassignedController::class, 'index'])->name('unassigned.index');

// §22.5 — reasignarea din „Unassigned" creează rânduri `bulk_operations` ca orice altă
// operație în masă, deci intră sub aceeași limită de 3 concurente per utilizator. Era una
// dintre cele două căi care o ocoleau (cealaltă e „Reassign and deactivate", în
// settings.php): limita aplicată doar pe `routes/web/bulk.php` ar fi lăsat exact fluxurile
// care creează CÂTE TREI operații dintr-un singur clic în afara ei.
Route::post('/unassigned/reassign', [UnassignedController::class, 'reassign'])
    ->middleware(EnsureBulkConcurrencyLimit::class)
    ->name('unassigned.reassign');
