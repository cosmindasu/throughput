<?php

// Catalog de produse — specs.md §10, Pachetul A. Inclus din routes/web.php, în grupul cu
// workspace (ADR-014 — `ResolveWorkspace` scoate `{workspace}` din parametrii poziționali,
// deci semnăturile de mai jos rămân `show(Product $product)`, fără `string $workspace`).

use App\Http\Controllers\Web\Products\ProductController;
use App\Http\Controllers\Web\Products\VariantController;
use Illuminate\Support\Facades\Route;

// `/products/create` ÎNAINTEA lui `/products/{product}`: aceeași adâncime de segment,
// iar dispatcher-ul Laravel potrivește rutele în ordinea înregistrării (convenția din
// routes/web/deals.php).
Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::post('/products', [ProductController::class, 'store'])->name('products.store');
Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

Route::get('/products/{product}/variants/create', [VariantController::class, 'create'])->name('variants.create');
Route::post('/products/{product}/variants', [VariantController::class, 'store'])->name('variants.store');
Route::get('/variants/{variant}/edit', [VariantController::class, 'edit'])->name('variants.edit');
Route::put('/variants/{variant}', [VariantController::class, 'update'])->name('variants.update');
Route::delete('/variants/{variant}', [VariantController::class, 'destroy'])->name('variants.destroy');
