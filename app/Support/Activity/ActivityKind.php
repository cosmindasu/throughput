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
 * Folosită de AMBELE resurse de activitate: `ActivityEntryResource` (feed-ul dashboard-ului)
 * și `Activity\ActivityLogResource` (pagina Activity Log), ca să nu poată diverge.
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
            $entry->auditable_type === Order::class
                && array_key_exists('shipment', $new) => 'order_shipped',
            default => 'updated',
        };
    }
}
