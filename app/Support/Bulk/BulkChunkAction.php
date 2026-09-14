<?php

namespace App\Support\Bulk;

/**
 * Un job de chunk (§13.2, pct. 5) execută UN action asupra unui set de id-uri. Fiecare
 * implementare trebuie să fie IDEMPOTENTĂ prin construcție — o actualizare CONDIȚIONATĂ pe
 * stare (ex: `UPDATE ... WHERE owner_user_id != :nou`), niciodată un increment necondiționat
 * — sigură la reîncercarea aceluiași chunk (`tries` > 1 pe `ProcessBulkChunkJob`).
 */
interface BulkChunkAction
{
    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $payload
     * @return int rândurile efectiv modificate de ACEST chunk (pentru diagnosticare)
     */
    public function apply(BulkWritableResource $resource, array $ids, array $payload): int;
}
