<?php

// FR-TEN-05 — vederea „Unassigned" (§6.4.1). Inclus din routes/web.php, în grupul cu
// workspace. Owner/Manager (`unassigned.view`, verificat în controller).

use App\Http\Controllers\Web\Members\UnassignedController;
use Illuminate\Support\Facades\Route;

Route::get('/unassigned', [UnassignedController::class, 'index'])->name('unassigned.index');
Route::post('/unassigned/reassign', [UnassignedController::class, 'reassign'])->name('unassigned.reassign');
