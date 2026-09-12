<?php

namespace App\Models;

use App\Concerns\AppendOnly;
use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address', 'user_agent'])]
#[Table('activity_log')]
class ActivityLog extends Model
{
    use AppendOnly, BelongsToTenant, HasUlids;

    // Tabela e `activity_log`, la SINGULAR — numele vine din §19.1 și e folosit ca atare
    // în migrație și în indexuri. Convenția Eloquent ar fi cerut `activity_logs`, deci
    // fără atributul de mai sus modelul interoghează o tabelă inexistentă. Nu e o
    // subtilitate teoretică: a picat feed-ul de pe dashboard cu 500 la prima cerere reală.

    // Jurnal de audit — tabela are doar `created_at`, fără `updated_at`.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
