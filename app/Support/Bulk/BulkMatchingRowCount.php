<?php

namespace App\Support\Bulk;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Code review P2-003 — sursă UNICĂ pentru „câte rânduri atinge de fapt o operație în
 * masă", folosită ATÂT de `App\Actions\Bulk\DispatchBulkOperationAction` (validare +
 * `bulk_operations.total_rows`), CÂT ȘI de `AccountController::index()`/
 * `DealController::index()` (N-ul din „Select all N matching this filter" și din
 * dialogul de confirmare, §13.1).
 *
 * Înainte de acest fix, controller-ele de listă numărau tot filtrul, fără
 * `scopeToOwnRecords()` — un Agent cu `filter[owner]=all` vedea „Select all 5 accounts
 * matching this filter" dar operația reasigna doar 2 (BR-BULK-02,
 * `ReassignOwnerTest::test_agent_can_only_reassign_accounts_they_own_even_when_the_filter_shows_more`).
 * O singură funcție elimină riscul ca lista și dispatch-ul să diveargă din nou.
 */
final class BulkMatchingRowCount
{
    public static function for(User $user, BulkWritableResource $resource, Builder $query): int
    {
        // Clonă — apelanții (`DispatchBulkOperationAction`, controllerele de listă)
        // refolosesc uneori interogarea de bază pentru altceva (paginare, `total_rows`
        // separat de numărătoare); scopul de proprietate nu trebuie să le mute pe amândouă.
        $query = clone $query;

        if (Permissions::restrictedToOwnRecords($user)) {
            $resource->scopeToOwnRecords($query, $user);
        }

        return (int) $query->toBase()->getCountForPagination();
    }
}
