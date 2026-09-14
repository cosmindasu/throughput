<?php

namespace App\Actions\Bulk;

use App\Support\Bulk\BulkChunkAction;
use App\Support\Bulk\BulkWritableResource;

/**
 * US-BULK-01, §13.3 — reasignare owner în masă, comună Accounts și Deals (aceeași coloană,
 * `owner_user_id`, pe ambele modele — vezi `AccountBulkResource`/`DealBulkResource`).
 *
 * Idempotentă prin construcție: `!=` exclude rândurile deja pe noul owner, deci o
 * reîncercare a ACELUIAȘI chunk (`tries` > 1) nu face nimic în plus — verificat de
 * `ReassignOwnerJobTest::test_running_the_same_chunk_twice_changes_nothing_the_second_time`.
 *
 * `orWhereNull`, NU doar `!=`: `owner_user_id` e nullabil pe `accounts` („Unassigned",
 * ADR-011), iar SQL cu logică pe trei valori face `NULL != :nou` să evalueze la `NULL`
 * (nici adevărat, nici fals) — un `WHERE` cu doar `!=` ar EXCLUDE tăcut exact rândurile
 * neatribuite, cele mai probabile ținte ale unei reasignări în masă. Găsit direct la
 * scriere, de `test_the_explicit_ids_mode_only_touches_the_selected_rows`.
 */
final class ReassignOwnerAction implements BulkChunkAction
{
    public function apply(BulkWritableResource $resource, array $ids, array $payload): int
    {
        $query = $resource->newQuery();
        $keyName = $query->getModel()->getKeyName();
        $newOwnerId = (string) $payload['owner_user_id'];

        return $query
            ->whereIn($keyName, $ids)
            ->where(fn ($q) => $q->where('owner_user_id', '!=', $newOwnerId)->orWhereNull('owner_user_id'))
            ->update(['owner_user_id' => $newOwnerId]);
    }
}
