<?php

// Căutare globală — FR-SEARCH-01/02. Inclus din routes/web.php, în grupul cu workspace.

use App\Http\Controllers\Web\SearchController;
use Illuminate\Support\Facades\Route;

// Apelat la fiecare tastă (debounced la 200ms în `GlobalSearch.tsx`, deci ~5 cereri/secundă
// în cel mai rău caz per utilizator activ) — limita e generoasă, doar ca plasă împotriva unui
// client rupt sau script automat, nu ca să încetinească tastarea normală (P3, code review).
Route::get('/search', [SearchController::class, 'index'])->middleware('throttle:120,1')->name('search');
