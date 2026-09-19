<?php

// Comenzi — FR-ORD-02…03, §11. Inclus din routes/web.php, în grupul cu workspace.

use App\Http\Controllers\Web\Orders\CancelOrderController;
use App\Http\Controllers\Web\Orders\ConfirmOrderController;
use App\Http\Controllers\Web\Orders\OrderController;
use App\Http\Controllers\Web\Orders\VariantLookupController;
use Illuminate\Support\Facades\Route;

// Segmentele statice ÎNAINTEA lui `/{order}` — la fel ca `routes/web/deals.php`:
// Laravel potrivește rutele în ordinea înregistrării, iar `orders/{order}` ar câștiga
// primul și ar căuta o comandă cu id-ul literal „create" sau „variants".
Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
Route::get('/orders/variants/lookup', VariantLookupController::class)->name('orders.variants.lookup');
// US-CRM-03, §13.2/§13.5 — la fel ca `routes/web/accounts.php`: segment static, ÎNAINTEA
// resursei, altfel „export" s-ar potrivi pe `/orders/{order}` ca id literal.
Route::get('/orders/export', [OrderController::class, 'export'])->name('orders.export');
Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
Route::get('/orders/{order}/edit', [OrderController::class, 'edit'])->name('orders.edit');
Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');
Route::patch('/orders/{order}/confirm', ConfirmOrderController::class)->name('orders.confirm');
Route::patch('/orders/{order}/cancel', CancelOrderController::class)->name('orders.cancel');
