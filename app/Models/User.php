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
use Spatie\Permission\PermissionRegistrar;
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
    use HasFactory, HasUlids, Notifiable;

    use HasRoles;

    /**
     * Rezultatele verificărilor de permisiune FĂRĂ model, pe instanța asta.
     *
     * Cheia e `{tenant}|{permisiune}`, nu doar permisiunea — vezi `can()`.
     *
     * @var array<string, bool>
     */
    private array $permissionChecks = [];

    /**
     * Memoizează DOAR forma „un singur nume, fără model".
     *
     * ## De ce
     *
     * Măsurat pe deployment-ul live (2026-09-29), pe `Deals/Kanban`, care randează ~300 de
     * carduri, fiecare cu două `Gate::allows`:
     *
     * | | 600 de apeluri |
     * |---|---|
     * | serializarea completă a board-ului | 399 ms |
     * | din care `Gate::allows` | 283 ms |
     * | din care `$user->can('deals.edit')` | 212 ms |
     * | `Permissions::restrictedToOwnRecords` | 12 ms |
     * | aceeași valoare, memoizată | **0,4 ms** |
     *
     * `DealPolicy::update()` e `$user->can('deals.edit') && isWithinOwnRecords(...)`.
     * Partea a doua chiar variază de la rând la rând (`owner_user_id`). Prima **nu poate**:
     * e setul de permisiuni al utilizatorului, recalculat de 600 de ori pentru un răspuns
     * care nu se schimbă. Asta e ~71% din costul board-ului, și atinge orice listă.
     *
     * Încărcarea relațiilor (`roles.permissions`) taie doar ~30% — costul nu e o
     * interogare, e reevaluarea. Memoizarea e singurul lucru care contează.
     *
     * ## Ce NU se memoizează, și de ce contează
     *
     * Verificările CU model (`can('update', $deal)`) trec neatinse. Ele sunt exact cele
     * care trebuie să difere de la rând la rând — memoizarea lor ar da unui Agent drepturi
     * pe deal-urile altcuiva, adică ar transforma o optimizare într-o breșă.
     * `PermissionMemoTest` fixează asta.
     *
     * ## De ce cheia conține tenantul
     *
     * `config/permission.php` are `'teams' => true` (`team_foreign_key = tenant_id`):
     * același om e Owner într-o organizație și Viewer în alta, deci răspunsul depinde de
     * tenantul CURENT. Iar tenantul chiar se schimbă în timpul unei cereri —
     * `UpdateMemberRoleAction` și `RevokeInvitationAction` cheamă `setPermissionsTeamId()`
     * pe tenantul membrului vizat, iar joburile de sistem iterează tenanții în același
     * proces (`TenantContext::run`). Un memo cheiat doar pe numele permisiunii ar
     * răspunde cu drepturile tenantului anterior — nu o optimizare, o scurgere.
     */
    public function can($abilities, $arguments = []): bool
    {
        if (! is_string($abilities) || $arguments !== []) {
            return parent::can($abilities, $arguments);
        }

        $key = app(PermissionRegistrar::class)->getPermissionsTeamId().'|'.$abilities;

        return $this->permissionChecks[$key] ??= parent::can($abilities);
    }

    /**
     * Invalidarea memo-ului. Cele două metode de mai jos sunt singurele cârlige care
     * funcționează REAL pentru un User.
     *
     * Prima variantă a acestui cod suprascria `forgetCachedPermissions()`, pe care toți
     * mutatorii par să-l cheme. Testul de retrogradare a picat, și sursa spune de ce —
     * `HasRoles::removeRole()`:
     *
     * ```php
     * if ($this instanceof Permission) {
     *     $this->forgetCachedPermissions();
     * }
     * ```
     *
     * Adică NICIODATĂ pentru un User. Cârligul era mort, iar memo-ul ar fi supraviețuit
     * unui `syncRoles()`: o retrogradare fără efect pe restul cererii.
     *
     * Ce se cheamă însă de fiecare dată, pe TOATE căile de mutație (`assignRole`,
     * `removeRole`, `syncRoles`, `givePermissionTo`, `revokePermissionTo`,
     * `syncPermissions`), e `unsetRelation('roles'|'permissions')` sau
     * `setRelation(…, collect())`. Deci acolo stă garda.
     *
     * Golirea la `setRelation` acoperă și încărcarea prin eager loading — nu e o pierdere:
     * relația tocmai s-a schimbat, deci memo-ul de dinainte n-are ce să mai garanteze.
     *
     * @param  string  $relation
     */
    public function setRelation($relation, $value): static
    {
        if ($relation === 'roles' || $relation === 'permissions') {
            $this->permissionChecks = [];
        }

        return parent::setRelation($relation, $value);
    }

    /**
     * Perechea celei de mai sus — vezi docblock-ul de acolo.
     *
     * @param  string  $relation
     */
    public function unsetRelation($relation): static
    {
        if ($relation === 'roles' || $relation === 'permissions') {
            $this->permissionChecks = [];
        }

        return parent::unsetRelation($relation);
    }

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
