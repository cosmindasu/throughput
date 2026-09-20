<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     *
     * Deal-urile șterse se numără și ele: rămân în bază pentru istoricul lor de etape (§9.2),
     * deci încă referă contul prin `deals.account_id`, iar DELETE-ul ar ajunge la FK.
     *
     * ADR-022, Lot I18N Val 2 — rescris de pe `Str::plural()` pe `trans_choice()`
     * (`lang/{en,fr}/flash.php`): `Str::plural()` aplică regula engleză de pluralizare
     * necondiționat, indiferent de `App::getLocale()` — pe franceză, unde 0 ȘI 1 sunt
     * singular (spre deosebire de engleză, unde doar 1 e singular), ar fi produs
     * gramatică greșită. Clauzele (deals/orders) sunt traduse separat și unite prin
     * `flash.common.list_and`, ca franceza să poată reordona fiecare bucată independent
     * de restul propoziției — nu concatenare directă de fragmente englezești.
     */
    public function deletionBlockedReason(): ?string
    {
        $deals = $this->deals()->withTrashed()->count();
        $deletedDeals = $deals > 0 ? $this->deals()->onlyTrashed()->count() : 0;
        $orders = $this->orders()->count();

        if ($deals === 0 && $orders === 0) {
            return null;
        }

        $parts = [];

        if ($deals > 0) {
            $dealsClause = trans_choice('flash.accounts.deletion_blocked_deals_clause', $deals, ['count' => $deals]);

            // Numărătoare SEPARATĂ de `$deals` (un subset al lui, cele șterse soft)
            // — cheie proprie, ca acordul „supprimée/supprimées" din franceză să
            // depindă de `$deletedDeals`, nu de `$deals` (vezi docblock-ul de mai sus).
            if ($deletedDeals > 0) {
                $dealsClause .= ' '.trans_choice('flash.accounts.deletion_blocked_deleted_note', $deletedDeals, ['deleted' => $deletedDeals]);
            }

            $parts[] = $dealsClause;
        }

        if ($orders > 0) {
            $parts[] = trans_choice('flash.accounts.deletion_blocked_orders_clause', $orders, ['count' => $orders]);
        }

        $joinedParts = count($parts) === 2
            ? $parts[0].' '.__('flash.common.list_and').' '.$parts[1]
            : $parts[0];

        return __('flash.accounts.deletion_blocked', ['parts' => $joinedParts]);
    }
}
