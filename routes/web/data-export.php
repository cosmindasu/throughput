<?php

// Export de date GDPR (specs.md §20.5, FR-GDPR-01/02, plan §11, lotul G).
// Inclus din routes/web.php, în grupul cu workspace.
// Owner declanșează (`data_exports.create`), Manager vede doar istoricul (`data_exports.view`).

use Illuminate\Support\Facades\Route;

Route::get('/settings/data-export', [\App\Http\Controllers\Web\Settings\DataExportController::class, 'index'])->name('settings.data-export.index');
Route::post('/settings/data-export', [\App\Http\Controllers\Web\Settings\DataExportController::class, 'store'])->name('settings.data-export.store');
Route::get('/settings/data-export/{dataExportRequest}/download', [\App\Http\Controllers\Web\Settings\DataExportController::class, 'download'])->name('settings.data-export.download');
