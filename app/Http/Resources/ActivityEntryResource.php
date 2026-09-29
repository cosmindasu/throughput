<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use App\Models\Membership;
use App\Support\Activity\ActivityActionLabel;
use App\Support\Members\DeactivatedMemberNames;
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
            'description' => $this->description(),
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
