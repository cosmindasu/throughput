<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `SoftDeletes`: istoricul de etape nu se șterge (§9.1/§9.2), iar `deal_stage_events.deal_id`
 * e o FK fără cascadă, deci un deal șters rămâne rândul spre care trimite istoricul lui.
 * Scope-ul îl scoate din liste, kanban, căutare și KPI-uri.
 */
#[Fillable([
    'account_id', 'primary_contact_id', 'pipeline_id', 'stage_id', 'owner_user_id',
    'title', 'value', 'currency', 'expected_close_date', 'status', 'lost_reason',
])]
class Deal extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'expected_close_date' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'primary_contact_id');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stageEvents(): HasMany
    {
        return $this->hasMany(DealStageEvent::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
