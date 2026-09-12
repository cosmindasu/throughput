<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['import_id', 'row_number', 'raw_data', 'status', 'errors', 'created_entity_id'])]
class ImportRow extends Model
{
    use BelongsToTenant, HasUlids;

    // Tabela nu are `created_at`/`updated_at`.
    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_SKIPPED = 'skipped';

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            // `raw_data` nu se șterge niciodată (BR-IMP-01) — rămâne aici indiferent de status.
            'raw_data' => 'array',
            'errors' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
