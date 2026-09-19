<?php

namespace App\Actions\Bulk;

use App\Models\Variant;
use App\Support\Bulk\BulkChunkAction;
use App\Support\Bulk\BulkWritableResource;
use Illuminate\Database\Query\Expression;

/**
 * §13.5 — actualizare de preț în masă pe Products/Variants (procent sau sumă fixă,
 * creștere sau scădere). `$ids` sunt id-uri de PRODUSE (`ProductBulkResource`); efectul se
 * aplică TUTUROR variantelor produselor selectate.
 *
 * Un `UPDATE` simplu, necondiționat pe valoarea rezultatului — nu poate fi idempotent „de
 * rând" (prețul nou depinde de cel VECHI, deci un `WHERE price != :nou` n-ar opri o a doua
 * aplicare, ar recalcula alt „nou" din prețul deja schimbat). Garanția că ACEST chunk nu se
 * aplică de două ori e la nivelul chunk-ului, nu aici — vezi docblock-ul
 * `App\Support\Bulk\BulkChunkAction` și `App\Jobs\Bulk\ProcessBulkChunkJob`
 * (`bulk_operation_chunks`, code review „P2-001": un marcaj pe VARIANTĂ nu supraviețuia
 * unei a doua operații pe același rând — 100 → 110 → 121 la o reîncercare între două
 * operații distincte, fiecare cu marcajul ei).
 *
 * Rotunjire la 2 zecimale, pe server; prețul nu coboară sub 0 (`GREATEST(..., 0)`) și nu
 * depășește `decimal(10,2)` (`LEAST(..., 99999999.99)`, code review „P3-002" — altfel o
 * creștere fixă mare arunca o eroare SQL de tip „numeric field overflow", care lăsa chunk-ul
 * eșuat tăcut, fără niciun rând schimbat, dar fără mesaj clar) — toate trei în ACEEAȘI
 * expresie SQL, nu recalculate în PHP înainte de UPDATE (ar cere un SELECT separat, cu
 * propria fereastră de concurență între citire și scriere).
 */
final class UpdatePriceAction implements BulkChunkAction
{
    /** Limita superioară a `variants.price` (`decimal(10,2)`) — 10 cifre, 2 zecimale. */
    private const MAX_PRICE = '99999999.99';

    public function apply(BulkWritableResource $resource, array $ids, array $payload): int
    {
        $mode = $payload['mode'] === 'percent' ? 'percent' : 'fixed';
        $sign = $payload['direction'] === 'decrease' ? -1 : 1;
        // Validat numeric în `UpdateProductPriceRequest` (percent: 0-100, fixed: > 0)
        // înainte să ajungă aici — cast defensiv, ca `sprintf('%.4f', ...)` de mai jos să
        // nu poată produce altceva decât o zecimală literală în SQL-ul brut.
        $amount = sprintf('%.4f', (float) $payload['amount']);

        $delta = $mode === 'percent'
            ? "(price * ({$sign} * {$amount} / 100))"
            : '('.($sign === -1 ? "-{$amount}" : $amount).')';

        $newPrice = new Expression(
            'LEAST(GREATEST(ROUND((price + '.$delta.')::numeric, 2), 0), '.self::MAX_PRICE.')'
        );

        return Variant::query()
            ->whereIn('product_id', $ids)
            ->update(['price' => $newPrice]);
    }
}
