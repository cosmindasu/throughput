<?php

namespace App\Http\Resources;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use App\Support\Activity\ActivityActionLabel;
use App\Support\Activity\ActivityKind;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Feed de activitate recentă a dashboard-ului (FR-DEMO-01) — ultimele 10 din
 * `activity_log`. `ActivityLog` nu are o coloană `description`: se compune aici din
 * `action` + `auditable_type`, o singură dată, nu recalculată în React (plan §1.2 regula 1).
 *
 * ADR-022/FR-I18N-04 — frazele sunt compuse prin `lang/{en,fr}/activity.php:entries`, nu
 * concatenate direct: `:subject` intră ca parametru de traducere, niciodată prin
 * interpolare de șir PHP, ca franceza să poată reordona cuvintele în jurul lui.
 *
 * @mixin ActivityLog
 */
class ActivityEntryResource extends JsonResource
{
    /**
     * @return array{id: string, action: string, description: string, actor: string, at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Valoarea BRUTĂ din enum (`ActivityLog::ACTIONS`), pe lângă fraza deja compusă —
            // exact ca `HistoryEntryResource`, care expune de mult `action` alături de
            // `actionLabel`. Feed-ul o folosește ca să distingă vizual o ștergere de o
            // creare; `description` rămâne singura sursă a TEXTULUI, nu se reconstruiește
            // nimic din `action` în React.
            'action' => $this->action,
            // „Ce s-a întâmplat", derivat — `updated` acoperă deopotrivă o mutare de etapă,
            // o factură plătită și o editare de titlu (vezi `ActivityKind`). Feed-ul alege
            // iconul și tenta din ASTA, nu din `action`, ca rândurile de rutină să rămână
            // neutre și doar consecințele reale să iasă în evidență.
            'kind' => ActivityKind::of($this->resource),
            'description' => $this->description(),
            // Numele entității atinse, ca al DOILEA câmp, nu interpolat în `description`:
            // fraza rămâne tradusă întreagă (FR-I18N-06), iar conținutul scris de
            // utilizator intră separat. `null` când entitatea nu mai există sau rândul
            // n-are subiect (login, export în masă).
            //
            // GDPR-02: citim numele CURENT al modelului, deci un contact anonimizat apare
            // cu placeholderul lui — feed-ul nu poate reînvia date șterse.
            'subjectName' => $this->subjectName(),
            // FR-TEN-04 — placeholder „(deactivated)" pe autorul unei acțiuni dacă
            // membership-ul lui în tenantul curent a fost dezactivat între timp.
            'actor' => DeactivatedMemberNames::label($this->user?->name, $this->user_id) ?? __('activity.entries.system_actor'),
            'at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function description(): string
    {
        // US-TEN-03 — `MembersController::applyDeactivation()` scrie `action = 'updated'`
        // pe un `Membership` (enum-ul Postgres al coloanei n-are o valoare dedicată,
        // §17.1): „Updated Membership" ar fi corect, dar opac. Un singur caz special,
        // înaintea switch-ului generic.
        if ($this->auditable_type === Membership::class
            && ($this->new_values['status'] ?? null) === Membership::STATUS_DEACTIVATED) {
            return __('activity.entries.member_deactivated');
        }

        $subject = $this->subjectLabel();

        // Tipurile DERIVATE primesc fraza lor; restul cad pe verbul din enum.
        $derived = match (ActivityKind::of($this->resource)) {
            'stage_moved' => __('activity.entries.stage_moved', ['subject' => $subject]),
            'invoice_paid' => __('activity.entries.invoice_paid', ['subject' => $subject]),
            'order_shipped' => __('activity.entries.order_shipped', ['subject' => $subject]),
            default => null,
        };

        if ($derived !== null) {
            return $derived;
        }

        return match ($this->action) {
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
            // trebui să devină imposibilă — păstrată totuși ca ultimă linie de apărare
            // (`ActivityActionLabel::resolve()`), ca să nu randeze niciodată o cheie brută.
            default => ActivityActionLabel::resolve($this->action),
        };
    }

    /**
     * Numele PROPRIU al înregistrării atinse (titlul afacerii, numărul comenzii…), nu tipul ei.
     * `null` dacă entitatea nu mai există sau tipul n-are un câmp de nume cunoscut.
     */
    private function subjectName(): ?string
    {
        $model = $this->auditable;

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

    /** Numele entității auditate, tradus — fallback pe `record` dacă tipul lipsește sau nu are cheie în catalog. */
    private function subjectLabel(): string
    {
        if ($this->auditable_type === null) {
            return __('activity.subjects.record');
        }

        $key = 'activity.subjects.'.Str::lower(class_basename($this->auditable_type));

        return Lang::has($key) ? __($key) : __('activity.subjects.record');
    }
}
