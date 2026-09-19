<?php

namespace App\Actions\Bulk;

use App\Enums\OrderStatus;
use App\Support\Bulk\BulkChunkAction;
use App\Support\Bulk\BulkWritableResource;

/**
 * §13.5 — anulare în masă a comenzilor, DOAR `draft` (§11.3: un draft n-are `reserved`,
 * deci anularea lui n-are niciun efect de stoc — spre deosebire de
 * `App\Actions\Orders\CancelOrderAction`, care mai eliberează rezervarea pe o comandă
 * `confirmed`, cazul individual, nu cel din masă).
 *
 * Idempotentă prin construcție: `UPDATE ... WHERE status = 'draft'`, exact rețeta din
 * `App\Support\Bulk\BulkChunkAction` — o reîncercare a ACELUIAȘI chunk găsește rândurile
 * deja `cancelled` (WHERE-ul nu le mai prinde), deci a doua rulare nu schimbă nimic.
 *
 * Rândurile care NU mai sunt `draft` la momentul chunk-ului (ex: confirmate concurent între
 * declanșare și execuție) rămân tăcut neatinse aici — la fel ca `BR-BULK-01`, un chunk care
 * atinge mai puține rânduri decât `total_rows` capturat la dispatch nu e o eroare.
 */
final class CancelDraftOrdersAction implements BulkChunkAction
{
    public function apply(BulkWritableResource $resource, array $ids, array $payload): int
    {
        $query = $resource->newQuery();
        $keyName = $query->getModel()->getKeyName();

        return $query
            ->whereIn($keyName, $ids)
            ->where('status', OrderStatus::Draft)
            ->update(['status' => OrderStatus::Cancelled]);
    }
}
