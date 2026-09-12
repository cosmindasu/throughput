<?php

namespace App\Models;

use App\Concerns\AppendOnly;
use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deal_id', 'from_stage_id', 'to_stage_id', 'changed_at', 'duration_in_previous_stage_seconds'])]
class DealStageEvent extends Model
{
    use AppendOnly, BelongsToTenant, HasUlids;

    // Tabela nu are `created_at`/`updated_at` — doar `changed_at` (vezi migrația).
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
            'duration_in_previous_stage_seconds' => 'integer',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'to_stage_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
