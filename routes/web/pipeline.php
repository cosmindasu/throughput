<?php

// Configurare pipeline și etape — FR-DEAL-02, BR-DEAL-01. Inclus din routes/web.php, în grupul cu workspace.

use App\Http\Controllers\Web\Pipeline\PipelineController;
use App\Http\Controllers\Web\Pipeline\StageController;
use Illuminate\Support\Facades\Route;

Route::get('/pipeline', [PipelineController::class, 'index'])->name('pipeline.index');

Route::post('/pipeline/stages', [StageController::class, 'store'])->name('pipeline.stages.store');

// ÎNAINTEA rutei cu `{stage}`: verb diferit (PUT vs. PATCH) deci nu s-ar coliza oricum, dar
// segmentul literal „order" stă primul din igienă — un `{stage}` declarat înaintea lui ar fi
// candidat la legare de model pe orice verb care s-ar suprapune mai târziu.
Route::put('/pipeline/stages/order', [StageController::class, 'reorder'])->name('pipeline.stages.reorder');

Route::patch('/pipeline/stages/{stage}', [StageController::class, 'update'])->name('pipeline.stages.update');
Route::delete('/pipeline/stages/{stage}', [StageController::class, 'destroy'])->name('pipeline.stages.destroy');
