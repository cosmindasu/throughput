<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['provider', 'credentials', 'is_active'])]
class TenantCarrierSetting extends Model
{
    use BelongsToTenant, HasUlids;

    protected function casts(): array
    {
        return [
            // ADR-010: credențialele nu stau niciodată în clar.
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }
}
