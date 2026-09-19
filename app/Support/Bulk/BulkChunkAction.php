<?php

namespace App\Support\Bulk;

/**
 * Un job de chunk (§13.2, pct. 5) execută UN action asupra unui set de id-uri.
 *
 * GARANȚIA DE IDEMPOTENȚĂ PROPRIU-ZISĂ e la nivel de CHUNK, nu aici: `ProcessBulkChunkJob`
 * ține un marcaj per `(bulk_operation_id, chunk)` (`App\Models\BulkOperationChunk`) și nu
 * mai cheamă `apply()` a doua oară pentru același chunk (code review „P2-001", decizia
 * proprietarului) — necesară fiindcă o acțiune al cărei efect depinde de valoarea VECHE a
 * rândului (un preț, de exemplu) nu se poate face idempotentă doar prin forma `UPDATE`-ului.
 *
 * RĂMÂNE totuși RECOMANDATĂ o implementare idempotentă „de rând" acolo unde e posibilă —
 * o actualizare CONDIȚIONATĂ pe stare (ex: `UPDATE ... WHERE owner_user_id != :nou`),
 * niciodată un increment necondiționat — ca strat suplimentar (nu singurul), util și în
 * afara reîncercărilor (ex: un chunk planificat de două ori din greșeală, pe rânduri
 * parțial suprapuse).
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
