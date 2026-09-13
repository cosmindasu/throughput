<?php

// Exporturi și operațiile lor în coadă — US-CRM-03, §13.2. Inclus din routes/web.php, în grupul cu workspace.

use App\Http\Controllers\Web\Exports\ExportController;
use Illuminate\Support\Facades\Route;

Route::get('/exports/{export}', [ExportController::class, 'show'])->name('exports.show');
Route::get('/exports/{export}/download', [ExportController::class, 'download'])->name('exports.download');
