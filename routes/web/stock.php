<?php

// Stoc — specs.md §10, Pachetul A. Inclus din routes/web.php, în grupul cu workspace.

use App\Http\Controllers\Web\Stock\StockController;
use App\Http\Controllers\Web\Stock\StockMovementController;
use Illuminate\Support\Facades\Route;

// `/history` ÎNAINTEA lui `/stock` simplu, ca „history" să nu fie interpretat de o rută
// mai scurtă (nu e cazul aici — adâncimi diferite — dar convenția rămâne cea din
// routes/web/deals.php: cea mai specifică segmentare, înregistrată prima).
Route::get('/variants/{variant}/stock/history', [StockMovementController::class, 'index'])->name('stock.history');
Route::get('/variants/{variant}/stock', [StockController::class, 'show'])->name('stock.show');
Route::post('/variants/{variant}/stock/receive', [StockController::class, 'receive'])->name('stock.receive');
Route::post('/variants/{variant}/stock/adjust', [StockController::class, 'adjust'])->name('stock.adjust');
Route::post('/variants/{variant}/stock/transfer', [StockController::class, 'transfer'])->name('stock.transfer');
