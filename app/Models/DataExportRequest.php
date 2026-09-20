<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['status', 'file_path', 'expires_at', 'requested_at', 'completed_at', 'error_message'])]
class DataExportRequest extends Model
{
    use BelongsToTenant, HasUlids;

    // Tabela nu are `created_at`/`updated_at` — doar `requested_at`/`completed_at`.
    public $timestamps = false;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * Stările din care cererea încă se poate mișca — ecranul face polling cât timp există
     * măcar una, și se oprește când nu mai există niciuna.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_PROCESSING];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * FR-GDPR-01 — linkul e valabil `EXPORT_RETENTION_DAYS` zile. Verificarea e pe TIMP, nu
     * doar pe `file_path`: linkul trebuie să moară exact la scadență, indiferent dacă
     * `App\Jobs\Gdpr\PruneExpiredDataExportsJob` a apucat să șteargă fișierul în noaptea
     * aceea. Simetric cu `BulkOperation::isExpired()`.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->file_path !== null
            && ! $this->isExpired();
    }
}
