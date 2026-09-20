<?php

namespace App\Actions\Bulk;

use App\Jobs\Bulk\PlanBulkOperationJob;
use App\Models\BulkOperation;
use App\Models\User;
use App\Support\Bulk\BulkChunkActions;
use App\Support\Bulk\BulkConfirmationThreshold;
use App\Support\Bulk\BulkMatchingRowCount;
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
     * @param  bool  $confirmed  FR-BULK-01, plan §9 — clientul l-a trecut prin dialogul de
     *                           confirmare (`selectedCount`/`total` peste
     *                           `BulkConfirmationThreshold::for()`). `false` implicit: doar
     *                           formularul care ȘTIE că a arătat dialogul trimite `true`.
     *
     * @throws ValidationException peste plafonul de rol (BR-BULK-02), peste plafonul absolut
     *                             DEMO_MODE (§22.2), sau peste pragul de confirmare fără `confirmed` (FR-BULK-01)
     */
    public function execute(
        User $user,
        BulkWritableResource $resource,
        string $action,
        ListQuery $listQuery,
        ?array $ids,
        array $actionPayload,
        ?string $groupId = null,
        bool $confirmed = false,
        // Lotul E (instrumentare de jurnal, §13.2 pct. 5 din raport) — capturate AICI,
        // din cererea HTTP care declanșează operația, fiindcă `App\Jobs\Bulk\
        // ProcessBulkChunkJob` rulează pe coadă, unde `request()` nu mai există.
        // `activity_log.ip_address`/`user_agent` NU sunt nullabile (migrația din Faza 1),
        // deci fallback-uri explicite, niciodată `null` propagat mai departe.
        string $ipAddress = '0.0.0.0',
        string $userAgent = 'system',
    ): BulkOperation {
        $restrictToOwnRecords = Permissions::restrictedToOwnRecords($user);

        $query = $ids !== null
            ? $resource->newQuery()->whereIn($resource->newQuery()->getModel()->getKeyName(), $ids)
            : app($resource->listClass())->query($listQuery, $user);

        // Rândurile EFECTIV atinse, nu tot filtrul/selecția — vezi docblock-ul
        // `BulkChunkActions::narrowQuery()` (defect (g) din v1.24, reprodus altfel pe
        // anularea de draft-uri dacă lipsea aici). No-op pentru orice acțiune care atinge
        // toată selecția (reasignare, preț, activare).
        $query = BulkChunkActions::narrowQuery($action, $query);

        // P2-003 (code review) — ACEEAȘI funcție cu care `AccountController`/
        // `DealController::index()` calculează N-ul din „Select all N matching this
        // filter": un singur loc aplică `scopeToOwnRecords()` pentru Agent (BR-BULK-02),
        // altfel lista și dispecerizarea pot diverge din nou.
        $total = BulkMatchingRowCount::for($user, $resource, $query);

        $roleCap = BulkConfirmationThreshold::rowCapForRole($user);

        if ($roleCap !== null && $total > $roleCap) {
            throw ValidationException::withMessages([
                'selection' => "This operation would affect {$total} rows, above your role's limit of {$roleCap} rows per operation.",
            ]);
        }

        if (DemoMode::exceedsBulkRowCap($total)) {
            throw ValidationException::withMessages(['selection' => DemoMode::bulkRowCapRefusal($total)]);
        }

        // P2-002 (code review), plan §9 — „o singură expresie într-un singur loc
        // (`BulkConfirmationThreshold`), citită și de server la validare, și de props
        // pentru dialog". Pragul se verifică pe ACELAȘI `$total`, deja restrâns la
        // subsetul propriu pentru Agent — nu pe numărul brut al filtrului.
        if (! $confirmed && BulkConfirmationThreshold::exceeds($user, $total)) {
            throw ValidationException::withMessages([
                'selection' => "This operation would affect {$total} rows and needs confirmation before it can start.",
            ]);
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
                // Lotul E — vezi docblock-ul parametrilor de mai sus. Citite de
                // `PlanBulkOperationJob::plan()` și propagate la fiecare
                // `ProcessBulkChunkJob`, pentru rândurile de `activity_log` scrise de
                // acțiunea în masă (§13.2 pct. 5, §13.3).
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ],
            'total_rows' => $total,
            'status' => BulkOperation::STATUS_PENDING,
            'group_id' => $groupId,
        ]);

        PlanBulkOperationJob::dispatch(app('tenant')->getKey(), $operation->getKey())->onQueue('bulk');

        return $operation;
    }
}
