<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['resource_type', 'original_filename', 'column_mapping', 'status', 'total_rows', 'valid_rows', 'error_rows', 'completed_at'])]
class Import extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_MAPPED = 'mapped';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'error_rows' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function importRows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
