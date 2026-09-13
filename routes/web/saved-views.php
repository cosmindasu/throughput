<?php

// Vizualizări salvate — FR-VIEW-01/02, specs.md §15. Inclus din routes/web.php, în grupul
// cu workspace: listele pe care se aplică (accounts, deals) sunt deja scopate pe tenant.

use App\Http\Controllers\Web\SavedViews\SavedViewController;
use App\Support\SavedViews\SavedViewResourceType;
use Illuminate\Support\Facades\Route;

Route::get('/saved-views/{resourceType}', [SavedViewController::class, 'index'])
    ->whereIn('resourceType', SavedViewResourceType::supported())
    ->name('saved-views.index');

Route::post('/saved-views', [SavedViewController::class, 'store'])->name('saved-views.store');

// `/apply` ÎNAINTEA lui `/{savedView}` simplu (nu există un GET pe un singur `savedView`
// aici, dar convenția rămâne cea din `routes/web/accounts.php`: segmentul special declarat
// primul).
Route::get('/saved-views/{savedView}/apply', [SavedViewController::class, 'apply'])->name('saved-views.apply');
Route::patch('/saved-views/{savedView}', [SavedViewController::class, 'update'])->name('saved-views.update');
Route::delete('/saved-views/{savedView}', [SavedViewController::class, 'destroy'])->name('saved-views.destroy');

Route::put('/saved-views/{resourceType}/default', [SavedViewController::class, 'setDefault'])
    ->whereIn('resourceType', SavedViewResourceType::supported())
    ->name('saved-views.default');
