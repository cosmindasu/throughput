<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * RTBF (specs.md §20.5) — un contact anonimizat (`anonymized_at` NOT NULL) rămâne în
 * bază pentru integritatea referențială a deals/orders istorice, dar dispare din orice
 * interogare NORMALĂ pe `Contact`: listă, export, căutare globală (`GlobalSearchService`),
 * opțiunile de „Primary contact" pe formularul de deal (`DealController::contactsForAccount()`),
 * verificarea de email duplicat (`DuplicateContactEmail`), lista de contacte a unui cont
 * (`Account::contacts()`) — toate trec prin `Contact::query()`, deci un singur scope
 * global le acoperă pe toate, fără cod repetat per loc.
 *
 * Se aplică și la binding-ul implicit de rută (`Contact $contact`): un id anonimizat
 * produce automat 404 pe `contacts.show/edit/update/destroy`, exact ca `TenantScope`
 * pentru un id din alt tenant (vezi `ContactIsolationTest`).
 *
 * Bypass explicit, cu `withoutGlobalScope(self::class)`, DOAR acolo unde contactul
 * anonimizat trebuie să rămână vizibil ca referință istorică — vezi
 * `DealController::show()` (contactul principal al unui deal, afișat ca text neutru).
 */
class NotAnonymizedContactScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getTable().'.anonymized_at');
    }
}
