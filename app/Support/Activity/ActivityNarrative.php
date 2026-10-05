<?php

namespace App\Support\Activity;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Fraza și subiectul unei intrări de jurnal, într-un SINGUR loc.
 *
 * Amândouă trăiau ca metode private în `ActivityEntryResource` (feed-ul dashboard-ului),
 * iar pagina `Activity/Index` nu avea acces la ele — deci afișa „Updated" acolo unde
 * dashboard-ul spunea „Moved Annual supply agreement to another stage". Același eveniment,
 * două ecrane, două povești. Docblock-ul lui `ActivityKind` chiar pretindea că ambele
 * resurse trec prin derivare; aici devine adevărat.
 *
 * ADR-022/FR-I18N-04 — frazele se compun prin `lang/{en,fr}/activity.php:entries`, cu
 * `:subject` ca PARAMETRU de traducere, niciodată prin concatenare de șir: franceza
 * reordonează cuvintele în jurul lui.
 */
final class ActivityNarrative
{
    /** Fraza gata compusă și tradusă — „Moved deal to another stage", „Marked invoice as paid". */
    public static function describe(ActivityLog $entry): string
    {
        // US-TEN-03 — `MembersController::applyDeactivation()` scrie `action = 'updated'`
        // pe un `Membership` (enum-ul Postgres al coloanei n-are o valoare dedicată,
        // §17.1): „Updated Membership" ar fi corect, dar opac. Un singur caz special,
        // înaintea switch-ului generic.
        if ($entry->auditable_type === Membership::class
            && ($entry->new_values['status'] ?? null) === Membership::STATUS_DEACTIVATED) {
            return __('activity.entries.member_deactivated');
        }

        $subject = self::subjectLabel($entry);

        // Tipurile DERIVATE primesc fraza lor; restul cad pe verbul din enum.
        $derived = match (ActivityKind::of($entry)) {
            'stage_moved' => __('activity.entries.stage_moved', ['subject' => $subject]),
            'invoice_paid' => __('activity.entries.invoice_paid', ['subject' => $subject]),
            'order_shipped' => __('activity.entries.order_shipped', ['subject' => $subject]),
            default => null,
        };

        if ($derived !== null) {
            return $derived;
        }

        return match ($entry->action) {
            'created' => __('activity.entries.created', ['subject' => $subject]),
            'updated' => __('activity.entries.updated', ['subject' => $subject]),
            'deleted' => __('activity.entries.deleted', ['subject' => $subject]),
            'login' => __('activity.entries.login'),
            'login_failed' => __('activity.entries.login_failed'),
            'exported' => __('activity.entries.exported', ['subject' => $subject]),
            'imported' => __('activity.entries.imported', ['subject' => $subject]),
            'bulk_action' => __('activity.entries.bulk_action', ['subject' => $subject]),
            'role_changed' => __('activity.entries.role_changed'),
            // Enum-ul e închis (migrația `2026_09_12_100080_...`), deci această ramură ar
            // trebui să devină imposibilă — păstrată totuși ca ultimă linie de apărare,
            // ca să nu randeze niciodată o cheie brută.
            default => ActivityActionLabel::resolve($entry->action),
        };
    }

    /**
     * Numele PROPRIU al înregistrării atinse (titlul afacerii, numărul comenzii…), nu tipul
     * ei. `null` dacă entitatea nu mai există sau tipul n-are un câmp de nume cunoscut.
     *
     * CITEȘTE relația `auditable`, deci apelantul trebuie s-o fi încărcat: proiectul
     * interzice lazy loading, iar fără `with('auditable')` asta ar arunca, nu ar încetini.
     *
     * GDPR-02: numele e cel CURENT al modelului, deci un contact anonimizat apare cu
     * placeholderul lui — jurnalul nu poate reînvia date șterse.
     */
    public static function subjectName(ActivityLog $entry): ?string
    {
        $model = $entry->auditable;

        if (! $model instanceof Model) {
            return null;
        }

        $name = match (true) {
            $model instanceof Deal => $model->title,
            $model instanceof Account, $model instanceof Product => $model->name,
            $model instanceof Contact => trim($model->first_name.' '.$model->last_name),
            $model instanceof Order => $model->order_number,
            $model instanceof Invoice => $model->invoice_number,
            $model instanceof Variant => $model->sku,
            default => null,
        };

        return ($name === null || $name === '') ? null : $name;
    }

    /** Numele TIPULUI auditat, tradus — fallback pe `record` dacă lipsește din catalog. */
    private static function subjectLabel(ActivityLog $entry): string
    {
        if ($entry->auditable_type === null) {
            return __('activity.subjects.record');
        }

        $key = 'activity.subjects.'.Str::lower(class_basename($entry->auditable_type));

        return Lang::has($key) ? __($key) : __('activity.subjects.record');
    }
}
