<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'domain', 'industry', 'billing_address', 'shipping_address',
    'phone', 'owner_user_id', 'tags', 'status', 'credit_terms', 'source',
])]
class Account extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_PROSPECT = 'prospect';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected function casts(): array
    {
        return [
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'tags' => 'array',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * BR-CRM-01 — ștergerea e blocată dacă există deals sau orders, cu mesaj explicit.
     *
     * O stare a contului, nu un drept al utilizatorului: NU ține în `AccountPolicy`, ca
     * `can.delete` să nu ascundă butonul fără explicație (§7.3 — „aplicația stricată").
     */
    public function deletionBlockedReason(): ?string
    {
        $deals = $this->deals()->count();
        $orders = $this->orders()->count();

        if ($deals === 0 && $orders === 0) {
            return null;
        }

        $parts = [];

        if ($deals > 0) {
            $parts[] = $deals.' '.Str::plural('deal', $deals);
        }

        if ($orders > 0) {
            $parts[] = $orders.' '.Str::plural('order', $orders);
        }

        return 'This account cannot be deleted: it has '.implode(' and ', $parts).'.';
    }
}
