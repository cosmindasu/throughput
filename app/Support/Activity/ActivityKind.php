<?php

namespace App\Support\Activity;

use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Order;

/**
 * „Ce s-a întâmplat", nu doar verbul din `activity_log.action`.
 *
 * Enum-ul coloanei e ÎNCHIS (migrația `2026_09_12_100080_...`), cu nouă valori. O mutare de
 * etapă, o factură plătită și o editare de titlu sunt, toate trei, `updated` — diferența
 * există doar în `auditable_type` + cheile din `new_values`. Pe dashboard, efectul era că
 * toate cele zece rânduri ale feed-ului scriau „Updated Deal", cu același punct, pe aceeași
 * tentă: un jurnal în care nimic nu iese în evidență fiindcă totul arată la fel.
 *
 * Derivarea se face AICI, o singură dată, și ajunge în React ca șir (`kind`) — niciodată
 * reconstruită din coloane în componente. `resources/js/lib/activityKind.ts` îi dă fiecărei
 * valori un icon și o tentă; o valoare fără intrare acolo cade pe neutru, nu pe o excepție.
 *
 * Folosită de AMBELE resurse care expun jurnal — `ActivityEntryResource` (feed-ul
 * dashboard-ului) și `Activity\ActivityLogResource` (pagina Activity Log) — plus, indirect,
 * de `ActivityNarrative::describe()`, care alege fraza după tipul derivat.
 *
 * Până la 2026-10-05 trecea DOAR prin feed, iar cele două ecrane chiar divergeau: aceeași
 * mutare de etapă apărea ca „Moved X to another stage" în feed și ca „Updated Deal" în
 * jurnal. Alinierea cerea un câmp nou pe contractul paginii (`kind`, `description`,
 * `subjectName`) și o revizie a testelor ei — s-a făcut, deci divergența NU mai există. Dacă
 * adaugi un tip derivat aici, adaugă-i și intrarea în `resources/js/lib/activityKind.ts`:
 * altfel ambele ecrane îl randează neutru, în tăcere.
 */
final class ActivityKind
{
    /** Tipurile derivate — cele care NU există ca valoare în enum-ul coloanei. */
    public const DERIVED = ['stage_moved', 'invoice_paid', 'order_shipped', 'member_deactivated'];

    public static function of(ActivityLog $entry): string
    {
        // Doar `updated` ascunde mai multe înțelesuri; restul verbelor sunt deja fără echivoc.
        if ($entry->action !== 'updated') {
            return $entry->action;
        }

        $new = $entry->new_values ?? [];

        return match (true) {
            $entry->auditable_type === Membership::class
                && ($new['status'] ?? null) === Membership::STATUS_DEACTIVATED => 'member_deactivated',
            $entry->auditable_type === Deal::class
                && (array_key_exists('stage_id', $new) || array_key_exists('stage', $new)) => 'stage_moved',
            $entry->auditable_type === Invoice::class
                && ($new['status'] ?? null) === Invoice::STATUS_PAID => 'invoice_paid',
            // Forma pe care o scrie EXPEDIEREA REALĂ: `MarkShipmentShippedAction` mută
            // comanda în `fulfilled`/`partially_fulfilled` prin `$order->save()`, deci
            // observerul înregistrează `{status: …}`. Prima versiune căuta o cheie
            // `shipment`, pe care o producea DOAR seed-ul demo — adică feed-ul semănat
            // spunea „Shipped ORD-123", iar o expediere adevărată spunea „Updated Order".
            // Demo-ul promitea o distincție pe care produsul n-o făcea.
            $entry->auditable_type === Order::class
                && in_array($new['status'] ?? null, [Order::STATUS_FULFILLED, Order::STATUS_PARTIALLY_FULFILLED], true) => 'order_shipped',
            default => 'updated',
        };
    }
}
