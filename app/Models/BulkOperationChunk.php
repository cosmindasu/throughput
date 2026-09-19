<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marcaj de idempotență PER CHUNK (§13.2, code review „P2-001", decizia proprietarului) —
 * vezi migrația `2026_09_14_160000_create_bulk_operation_chunks_table`. Scris exclusiv de
 * `App\Jobs\Bulk\ProcessBulkChunkJob::handle()`, cu `insertOrIgnore()` (evită evenimentele
 * Eloquent, deci `id`/`tenant_id`/`created_at` se completează explicit la apelare, ca în
 * `App\Actions\Stock\Concerns\LocksInventoryLevels`) — niciodată prin `create()`.
 *
 * Fără `updated_at`: rândul e imuabil odată scris (un marcaj „s-a întâmplat", nu o stare
 * care se schimbă).
 */
#[Fillable(['bulk_operation_id', 'chunk'])]
class BulkOperationChunk extends Model
{
    use BelongsToTenant, HasUlids;

    const UPDATED_AT = null;

    public function bulkOperation(): BelongsTo
    {
        return $this->belongsTo(BulkOperation::class);
    }
}
