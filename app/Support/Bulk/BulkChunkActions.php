<?php

namespace App\Support\Bulk;

use App\Actions\Bulk\CancelDraftOrdersAction;
use App\Actions\Bulk\ReassignOwnerAction;
use App\Actions\Bulk\SetProductActiveAction;
use App\Actions\Bulk\UpdatePriceAction;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Harta `action` (coloana `bulk_operations.action`) → executorul de chunk care o știe face.
 * Un singur executor poate servi mai multe `resource_type` (ex: `ReassignOwnerAction`
 * lucrează la fel pe Accounts și Deals) — genericitatea e pe `BulkWritableResource`, nu aici.
 */
final class BulkChunkActions
{
    public const REASSIGN_OWNER = 'reassign_owner';

    public const CANCEL_DRAFT_ORDERS = 'cancel_draft_orders';

    public const UPDATE_PRICE = 'update_price';

    public const SET_ACTIVE = 'set_active';

    /** @return array<string, class-string<BulkChunkAction>> */
    public static function map(): array
    {
        return [
            self::REASSIGN_OWNER => ReassignOwnerAction::class,
            self::CANCEL_DRAFT_ORDERS => CancelDraftOrdersAction::class,
            self::UPDATE_PRICE => UpdatePriceAction::class,
            self::SET_ACTIVE => SetProductActiveAction::class,
        ];
    }

    /**
     * Îngustarea rândurilor EFECTIV atinse de o acțiune, aplicată ÎNAINTE de numărătoare
     * (`App\Actions\Bulk\DispatchBulkOperationAction`) ȘI de planificare
     * (`App\Jobs\Bulk\PlanBulkOperationJob`) — nu doar la executarea `UPDATE`-ului din
     * chunk. Necesară pentru acțiuni care ating doar un SUBSET al selecției: anularea în
     * masă a comenzilor atinge doar `draft` (§13.5), deci „Select all N" și pragul de
     * confirmare trebuie să numere DOAR draft-urile din filtru/selecție, nu tot filtrul —
     * altfel capcana (g) din v1.24 („Select all N" nesincron cu ce atinge operația) se
     * reproduce pe un tip nou de acțiune, cu o cauză diferită de data asta (subset de
     * STARE, nu de PROPRIETATE). Restul acțiunilor ating toată selecția — `default` e
     * identitatea.
     */
    public static function narrowQuery(string $action, Builder $query): Builder
    {
        return match ($action) {
            self::CANCEL_DRAFT_ORDERS => $query->where('status', OrderStatus::Draft),
            default => $query,
        };
    }

    public static function resolve(string $action): BulkChunkAction
    {
        $class = self::map()[$action] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Acțiunea în masă \"{$action}\" nu are un executor de chunk înregistrat.");
        }

        return app($class);
    }

    /**
     * Code review P3 — distinge o operație bazată pe `Bus::batch()` (`reassign_owner` și
     * orice acțiune viitoare din `map()`) de un export (`action === 'export'`,
     * `App\Support\Exports\ListExport`): exporturile rulează ca UN job unic
     * (`ExportListJob`), fără batch și fără `batch_id`, deci n-au ce anula prin
     * `$batch->cancel()` și nu au o pagină de progres cu „Cancel" (`Bulk/Show`) —
     * rămân pe ruta lor (`exports.show`). Folosit de `BulkOperationPolicy::cancel()` și
     * de `BulkOperationController::show()`.
     */
    public static function isRegistered(string $action): bool
    {
        return array_key_exists($action, self::map());
    }
}
