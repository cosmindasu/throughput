<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FR-VIEW-02 — vederea implicită a UNUI utilizator, pentru O listă, în workspace-ul curent.
 * `saved_view_id` nul înseamnă „vederea implicită a fost ștearsă de altcineva" (FK
 * `nullOnDelete`, vezi migrația) — `SavedViewDefaultRedirect` citește exact acest caz.
 */
#[Fillable(['resource_type', 'saved_view_id'])]
class SavedViewDefault extends Model
{
    use BelongsToTenant, HasUlids;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function savedView(): BelongsTo
    {
        return $this->belongsTo(SavedView::class);
    }
}
