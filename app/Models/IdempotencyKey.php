<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'request_hash', 'response_body', 'response_status', 'expires_at'])]
class IdempotencyKey extends Model
{
    use BelongsToTenant, HasUlids;

    // Tabela nu are `created_at`/`updated_at` — doar `expires_at` (24h — §18.4).
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_status' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
