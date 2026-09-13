<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Models\Scopes\NotAnonymizedContactScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `anonymized_at` NU e în #[Fillable]: se scrie o singură dată, din
 * `App\Support\Contacts\ContactErasure`, prin `forceFill()`, niciodată din input HTTP.
 */
#[Fillable(['account_id', 'first_name', 'last_name', 'email', 'phone', 'title', 'is_primary', 'opt_out'])]
class Contact extends Model
{
    use BelongsToTenant, HasUlids;

    /**
     * §20.5 — vezi `NotAnonymizedContactScope`: contactele anonimizate dispar din orice
     * interogare normală, inclusiv din binding-ul implicit de rută.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new NotAnonymizedContactScope);
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'opt_out' => 'boolean',
            'anonymized_at' => 'datetime',
        ];
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'primary_contact_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
