<?php

// Căutare globală — FR-SEARCH-01/02. Inclus din routes/web.php, în grupul cu workspace.

use App\Http\Controllers\Web\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/search', [SearchController::class, 'index'])->name('search');
