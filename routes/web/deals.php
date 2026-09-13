<?php

// Deals și kanban — FR-DEAL-01, FR-DEAL-03, §9.3. Inclus din routes/web.php, în grupul cu workspace.

use App\Http\Controllers\Web\Deals\DealController;
use App\Http\Controllers\Web\Deals\DealStageController;
use Illuminate\Support\Facades\Route;

// `/board` și `/create` ÎNAINTEA lui `/{deal}`: același „adâncime" de segment, iar
// dispatcher-ul Laravel potrivește rutele în ordinea în care sunt înregistrate.
Route::get('/deals/board', [DealController::class, 'board'])->name('deals.board');
Route::get('/deals/create', [DealController::class, 'create'])->name('deals.create');
Route::get('/deals', [DealController::class, 'index'])->name('deals.index');
Route::post('/deals', [DealController::class, 'store'])->name('deals.store');
Route::get('/deals/{deal}/edit', [DealController::class, 'edit'])->name('deals.edit');
Route::get('/deals/{deal}', [DealController::class, 'show'])->name('deals.show');
Route::put('/deals/{deal}', [DealController::class, 'update'])->name('deals.update');
Route::delete('/deals/{deal}', [DealController::class, 'destroy'])->name('deals.destroy');
Route::patch('/deals/{deal}/stage', [DealStageController::class, 'move'])->name('deals.stage.update');
