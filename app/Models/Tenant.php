<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

/**
 * Organizația client — unitatea de scopare a întregii aplicații (§6).
 *
 * Nu are `tenant_id` și nu are RLS: e chiar lucrul după care se scopează restul.
 * `Billable` e aici, nu pe `User` (ADR-006): abonamentul e al organizației.
 */
#[Fillable(['name', 'slug', 'industry', 'currency'])]
class Tenant extends Model
{
    use Billable, HasUlids;

    /** Segmentul de cale din ADR-002: `/{workspace}/...` e slug-ul, nu ULID-ul. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'subscription_canceled_at' => 'datetime',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function carrierSettings(): HasMany
    {
        return $this->hasMany(TenantCarrierSetting::class);
    }
}
