<?php

namespace App\Support\Bulk\Resources;

use App\Models\Contact;
use App\Models\User;
use App\Support\Bulk\BulkWritableResource;
use App\Support\Lists\ContactList;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contacte — GDPR-04 (Art. 21/17), §13.5: opt-out în masă și ștergere/anonimizare RTBF.
 * Ownership „prin cont", ca `App\Policies\ContactPolicy::isWithinOwnRecords()`: contactul
 * n-are `owner_user_id` propriu (§8.1) — un Agent lucrează pe contactele create de el SAU
 * ale căror cont îl are pe el ca responsabil (`accounts.owner_user_id`).
 *
 * `Contact::query()` poartă deja global scope-ul `App\Models\Scopes\NotAnonymizedContactScope`
 * (§20.5): un contact deja anonimizat dispare de aici la fel ca din listă/căutare/export —
 * inclusiv când selecția vine ca set explicit de ID-uri (checkbox de pagină curentă), nu
 * doar din filtrul „Select all matching". Un id anonimizat concurent între afișarea paginii
 * și declanșarea operației se comportă exact ca un id din alt tenant: dispare tăcut din
 * numărătoare și din chunk, fără eroare (§13.2 pct. 5, BR-BULK-01).
 */
final class ContactBulkResource implements BulkWritableResource
{
    public function resourceType(): string
    {
        return 'contacts';
    }

    public function listClass(): string
    {
        return ContactList::class;
    }

    public function modelClass(): string
    {
        return Contact::class;
    }

    public function newQuery(): Builder
    {
        return Contact::query();
    }

    public function scopeToOwnRecords(Builder $query, User $user): void
    {
        $query->where(fn (Builder $owned) => $owned
            ->where('created_by', $user->getKey())
            ->orWhereHas('account', fn (Builder $account) => $account->where('owner_user_id', $user->getKey())));
    }
}
