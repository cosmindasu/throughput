<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;

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

    /**
     * BUG DE FUNDAȚIE găsit la testarea lotului de abonament (Faza 5, specs.md §12.2),
     * reparat aici, nu în migrație — corectare, nu presupunere.
     *
     * `Laravel\Cashier\Concerns\ManagesSubscriptions::subscriptions()` (mixat prin
     * `Billable`, mai sus) NU specifică o cheie externă: cheamă
     * `$this->hasMany(Subscription::class, $this->getForeignKey())`. Implicitul Eloquent
     * pentru `getForeignKey()` e `Str::snake(class_basename($this)).'_id'` — pentru acest
     * model, `tenant_id`. Migrația `subscriptions` (Faza 1, ADR-006) a păstrat însă
     * DELIBERAT numele de coloană `user_id` („implicitul Cashier"), exact ca restul
     * pachetului să nu ceară suprascrieri — dar relația `HasMany` de mai sus TOT calculează
     * cheia din numele CLASEI (`Tenant`), nu din numele coloanei migrate, deci interoga
     * `subscriptions.tenant_id`, o coloană inexistentă (`SQLSTATE[42703]`, reprodus de
     * `SubscriptionAccessPolicy`/`ProcessStripeWebhookJob` la primul webhook procesat).
     *
     * Suprascriere ȚINTITĂ, NU un `getForeignKey()` global pe clasă: `memberships()`,
     * `accounts()`, `orders()`, `carrierSettings()` de mai sus se bazează CORECT pe
     * implicitul `tenant_id` al aceleiași metode — schimbarea ei ar fi reparat Cashier
     * stricând tot restul modelului. Doar relația asta (și, prin `$this->subscriptions`,
     * `subscription()`/`subscribed()` din trait) primește cheia corectă.
     *
     * Nota simetrică pe partea cealaltă (`Subscription::owner()`/`user()`, tot din Cashier,
     * cu același `getForeignKey()`) rămâne neatinsă — codul acestui lot navighează mereu
     * dinspre Tenant, niciodată dinspre Subscription, deci acel sens nu e exercitat; dacă
     * un lot viitor are nevoie de el, aceeași corecție se aplică simetric.
     *
     * DOM-03 (audit 2026-09-23) — `orderBy('created_at', 'desc')` fără tiebreaker:
     * `subscriptions.created_at` e `timestamp(0)` ca restul proiectului
     * (`.ai/rules/tenancy.md`, „created_at are precizie 0"), deci două abonamente scrise
     * în aceeași secundă (webhook Stripe + o rescriere manuală, sau două tranziții de
     * status procesate la câteva milisecunde distanță) n-aveau niciun departajor —
     * ordinea la egalitate era nedeterministă. Alimentează `subscription()`/`subscribed()`
     * (din trait-ul `Billable`, prin `$this->subscriptions`) și
     * `App\Support\Billing\SubscriptionAccessPolicy`, care citesc „abonamentul curent" ca
     * primul din listă. `id` e auto-increment (migrația Fazei 1, tabelă fără RLS — vezi
     * migrația `subscriptions`), deci reflectă mereu corect ordinea de inserare.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Cashier::$subscriptionModel, 'user_id')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');
    }
}
