<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Identitate GLOBALĂ (§19.1): fără `tenant_id`, fără RLS.
 *
 * Rolul nu e o coloană aici — e per tenant, prin `spatie/laravel-permission` cu teams
 * (`team_foreign_key = tenant_id`, §7.2): același om poate fi Owner într-o organizație și
 * Viewer în alta.
 */
#[Fillable(['name', 'email', 'password', 'theme', 'locale', 'dismissed_hints'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUlids, Notifiable;

    /**
     * ADR-022, specs.md §15.8 FR-I18N-05 — leagă `users.locale` de mecanismul nativ
     * Laravel de localizare a corespondenței (`Mailable::locale()`/coada de notificări),
     * în loc să repete manual `->locale($user->locale)` peste tot unde se trimite ceva
     * unui utilizator. Fără acest contract, `users.locale` n-are niciun efect automat pe
     * notificări — ADR-022, „Consecințe", pct. 3.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'dismissed_hints' => 'array',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
