<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'saved_view_id', 'report_type', 'name', 'format', 'schedule_frequency',
    'schedule_time', 'schedule_day', 'recipients', 'is_active',
])]
class ReportDefinition extends Model
{
    use BelongsToTenant, HasUlids;

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
}
