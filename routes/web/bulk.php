<?php

// Operații în masă de SCRIERE — mecanismul generic (§13.2), aplicat în acest val pe
// Accounts și Deals (§13.5, reasignare owner, US-BULK-01). Inclus din routes/web.php, în
// grupul cu workspace. Distinct de `routes/web/exports.php` (operații de CITIRE, Faza 2):
// modelul `BulkOperation` e comun, dar prezentarea diferă (progres + „Cancel", nu
// „Download").
//
// `{resourceType}` restricționat la nivel de rută, ca `AccountLookupController` — orice
// altă valoare e 404 înainte să ajungă la `BulkWritableResources::resolve()`.

use App\Http\Controllers\Web\Bulk\BulkOperationController;
use App\Http\Controllers\Web\Bulk\BulkOperationGroupController;
use Illuminate\Support\Facades\Route;

Route::post('/{resourceType}/bulk/reassign-owner', [BulkOperationController::class, 'reassignOwner'])
    ->whereIn('resourceType', ['accounts', 'deals', 'orders'])
    ->name('bulk.reassign-owner');

// Lotul E (valul „bulk", faza Comenzi/Produse) — acțiuni specifice unei singure resurse,
// fără segmentul `{resourceType}` de mai sus (spre deosebire de reasignarea de owner,
// comună la trei resurse).
Route::post('/orders/bulk/cancel-drafts', [BulkOperationController::class, 'cancelDraftOrders'])->name('bulk.orders.cancel-drafts');
Route::post('/products/bulk/update-price', [BulkOperationController::class, 'updateProductPrice'])->name('bulk.products.update-price');
Route::post('/products/bulk/set-active', [BulkOperationController::class, 'setProductActive'])->name('bulk.products.set-active');

Route::get('/bulk/{operation}', [BulkOperationController::class, 'show'])->name('bulk.show');
Route::post('/bulk/{operation}/cancel', [BulkOperationController::class, 'cancel'])->name('bulk.cancel');

// BR-BULK-04 — progresul AGREGAT al unui `group_id` (US-TEN-03, „Membri și roluri"):
// `{group}` e un ULID de coloană (`bulk_operations.group_id`), NU un model legat — mai
// multe rânduri `bulk_operations` îl împart. Trei segmente (`/bulk/groups/{group}`), deci
// fără ambiguitate cu `/bulk/{operation}` de mai sus (două segmente) — ordinea de
// înregistrare nu contează aici, dar rutele stau grupate una lângă alta pentru lizibilitate.
Route::get('/bulk/groups/{group}', [BulkOperationGroupController::class, 'show'])->name('bulk.groups.show');
Route::post('/bulk/groups/{group}/cancel', [BulkOperationGroupController::class, 'cancel'])->name('bulk.groups.cancel');
