<?php

namespace App\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stratul 1 din ADR-003: global scope Eloquent + completarea automată a lui `tenant_id`.
 *
 * Stratul 2 (RLS) e în migrație, nu aici — vezi Database\Migrations\Concerns\EnablesRowLevelSecurity.
 * Cele două sunt independente deliberat: o scurgere cere ambele să greșească simultan.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $model->tenant_id ??= TenantScope::currentTenantId();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
