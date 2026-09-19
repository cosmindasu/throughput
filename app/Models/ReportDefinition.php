<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'saved_view_id', 'report_type', 'name', 'format', 'schedule_frequency',
    'schedule_time', 'schedule_day', 'recipients', 'is_active',
])]
class ReportDefinition extends Model
{
    use BelongsToTenant, HasUlids;

    // §16.1 — sursa e o familie XOR: `saved_view_export` cu `saved_view_id` populat, sau
    // unul din rapoartele built-in cu `saved_view_id` nul. `report_type` NU e null în
    // practică pentru un rând bine format, deși coloana rămâne nullabilă în schemă (a se
    // vedea nota de contradicție din raportul lotului K, Faza 4 — specs.md §16.1 pune
    // „Nul dacă provine dintr-un saved_view" pe rândul GREȘIT al tabelului).
    public const TYPE_SAVED_VIEW_EXPORT = 'saved_view_export';

    public const TYPE_DEAL_VELOCITY = 'deal_velocity';

    public const TYPE_INVENTORY_VALUATION = 'inventory_valuation';

    public const FORMAT_CSV = 'csv';

    public const FORMAT_XLSX = 'xlsx';

    public const FORMAT_PDF = 'pdf';

    public const FREQUENCY_NONE = 'none';

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function savedView(): BelongsTo
    {
        return $this->belongsTo(SavedView::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reportRuns(): HasMany
    {
        return $this->hasMany(ReportRun::class);
    }

    public function isBuiltIn(): bool
    {
        return $this->report_type !== self::TYPE_SAVED_VIEW_EXPORT;
    }

    /**
     * Cel mai recent `report_runs`, eager-loadabil (`->with('latestRun')`, fără N+1 pe
     * `Reports/Index`) — `ofMany('created_at', 'max')`, pe coloana reală adăugată la
     * review (fix P2: fosta decodare din ULID nu era indexabilă/interogabilă direct).
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(ReportRun::class)->ofMany('created_at', 'max');
    }
}
