<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'resource_type', 'action', 'filter_snapshot', 'total_rows', 'batch_id',
    'group_id', 'status', 'result_path', 'error_message', 'expires_at',
])]
class BulkOperation extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'filter_snapshot' => 'array',
            'total_rows' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * exports.download — doar autorul, doar când fișierul chiar există (§13.2, DoD pachet A)
     * și linkul n-a expirat (FR-GDPR-01, §20.5: 7 zile). Verificarea e pe timp, nu doar pe
     * `result_path`: linkul trebuie să moară exact la scadență, indiferent dacă
     * `PruneExpiredExportsJob` a rulat deja fizic ștergerea fișierului în noaptea aceea.
     */
    public function isDownloadableBy(User $user): bool
    {
        return $this->user_id === $user->getKey()
            && $this->status === self::STATUS_COMPLETED
            && $this->result_path !== null
            && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
