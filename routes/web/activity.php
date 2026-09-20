<?php

// Jurnal de activitate — FR-AUD-01…04, US-AUD-01 (specs.md §17, Faza 5, lotul E). Inclus
// din routes/web.php, în grupul cu workspace.
//
// `{type}` restricționat la alias-urile din App\Support\Activity\AuditableResources — orice
// altă valoare e 404 înainte să ajungă la controller, ca la `{resourceType}` din
// routes/web/bulk.php.

use App\Http\Controllers\Web\ActivityLogController;
use App\Support\Activity\AuditableResources;
use Illuminate\Support\Facades\Route;

Route::get('/activity', [ActivityLogController::class, 'index'])->name('activity.index');

Route::get('/activity/entity/{type}/{id}', [ActivityLogController::class, 'forEntity'])
    ->whereIn('type', array_keys(AuditableResources::map()))
    ->name('activity.entity');
