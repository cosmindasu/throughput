<?php

namespace App\Actions\Bulk;

use App\Support\Bulk\BulkChunkAction;
use App\Support\Bulk\BulkWritableResource;

/**
 * §13.5 — activare/dezactivare în masă a produselor. Toggle simplu pe `products.is_active`
 * (coloana pe care se filtrează și `Products/Index`, `ProductList::applyFilters()`), NU pe
 * variante — simetric cu `UpdatePriceAction`, care operează pe variantele produselor
 * selectate, dar starea activă/inactivă afișată pe listă e a PRODUSULUI.
 *
 * Idempotentă prin construcție, ca `ReassignOwnerAction`: `UPDATE ... WHERE is_active !=
 * :nou` — o reîncercare a aceluiași chunk nu mai găsește rânduri de schimbat.
 */
final class SetProductActiveAction implements BulkChunkAction
{
    public function apply(BulkWritableResource $resource, array $ids, array $payload): int
    {
        $active = (bool) $payload['active'];
        $query = $resource->newQuery();
        $keyName = $query->getModel()->getKeyName();

        return $query
            ->whereIn($keyName, $ids)
            ->where('is_active', '!=', $active)
            ->update(['is_active' => $active]);
    }
}
