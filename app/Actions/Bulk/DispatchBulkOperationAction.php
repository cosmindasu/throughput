<?php

namespace App\Actions\Bulk;

use App\Jobs\Bulk\PlanBulkOperationJob;
use App\Models\BulkOperation;
use App\Models\User;
use App\Support\Bulk\BulkConfirmationThreshold;
use App\Support\Bulk\BulkWritableResource;
use App\Support\DemoMode;
use App\Support\ListQuery;
use App\Support\Permissions;
use Illuminate\Validation\ValidationException;

/**
 * §13.1/§13.2 — capturează filtrul (pattern „Select all N matching this filter") SAU un set
 * explicit de id-uri (checkbox de pagină curentă) — NICIODATĂ o listă rezolvată de rânduri —
 * apoi pune un job PLANIFICATOR în coadă. Planificatorul re-rulează interogarea pe cursor, în
 * chunk-uri, și le grupează într-un `Bus::batch()` (`App\Jobs\Bulk\PlanBulkOperationJob`).
 *
 * Un rând `bulk_operations` per `resource_type` (BR-BULK-04); `$groupId`, opțional, leagă mai
 * multe rânduri ale ACELEIAȘI acțiuni de utilizator — neconstruit în acest val (US-TEN-03 nu
 * există încă în cod, vezi raportul), dar mecanismul acceptă parametrul fără schimbare de formă,
 * per cerința task-ului („progresul agregat pe group_id trebuie să fie posibil").
 */
final class DispatchBulkOperationAction
{
    /**
     * @param  list<string>|null  $ids  set explicit (checkbox de pagină); `null` = tot filtrul curent
     * @param  array<string, mixed>  $actionPayload
     *
     * @throws ValidationException peste plafonul de rol (BR-BULK-02) sau peste plafonul absolut DEMO_MODE (§22.2)
     */
    public function execute(
        User $user,
        BulkWritableResource $resource,
        string $action,
        ListQuery $listQuery,
        ?array $ids,
        array $actionPayload,
        ?string $groupId = null,
    ): BulkOperation {
        $restrictToOwnRecords = Permissions::restrictedToOwnRecords($user);

        $query = $ids !== null
            ? $resource->newQuery()->whereIn($resource->newQuery()->getModel()->getKeyName(), $ids)
            : app($resource->listClass())->query($listQuery, $user);

        if ($restrictToOwnRecords) {
            $resource->scopeToOwnRecords($query, $user);
        }

        $total = (int) (clone $query)->toBase()->getCountForPagination();

        $roleCap = BulkConfirmationThreshold::rowCapForRole($user);

        if ($roleCap !== null && $total > $roleCap) {
            throw ValidationException::withMessages([
                'selection' => "This operation would affect {$total} rows, above your role's limit of {$roleCap} rows per operation.",
            ]);
        }

        if (DemoMode::exceedsBulkRowCap($total)) {
            throw ValidationException::withMessages(['selection' => DemoMode::bulkRowCapRefusal($total)]);
        }

        $operation = BulkOperation::create([
            'user_id' => $user->getKey(),
            'resource_type' => $resource->resourceType(),
            'action' => $action,
            // Snapshot-ul ține și ce NU face parte din `ListQuery` (filtru/sort): setul
            // explicit de id-uri, payload-ul acțiunii și cine a declanșat-o — planificatorul
            // (job, fără cerere HTTP) are nevoie de identitatea actorului ca să refacă
            // `owner=me` și restricția de rol exact cum arătau la momentul dispatch-ului.
            // `ResourceList::fromState()` citește doar `filter`/`sort` din acest array și
            // ignoră restul — nicio coliziune de chei.
            'filter_snapshot' => [
                ...$listQuery->toArray(),
                'ids' => $ids,
                'action_payload' => $actionPayload,
                'actor_id' => $user->getKey(),
                'restrict_to_own_records' => $restrictToOwnRecords,
            ],
            'total_rows' => $total,
            'status' => BulkOperation::STATUS_PENDING,
            'group_id' => $groupId,
        ]);

        PlanBulkOperationJob::dispatch(app('tenant')->getKey(), $operation->getKey())->onQueue('bulk');

        return $operation;
    }
}
