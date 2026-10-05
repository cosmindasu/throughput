<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use App\Support\Activity\ActivityKind;
use App\Support\Activity\ActivityNarrative;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
     * @return array{id: string, action: string, kind: string, description: string, subjectName: string|null, actor: string, at: string|null}
     */
    public function toArray(Request $request): array
    {
        // Calculat O DATĂ și pasat mai departe — vezi `ActivityNarrative::describe()`.
        $kind = ActivityKind::of($this->resource);

        return [
            'id' => $this->id,
            // Valoarea BRUTĂ din enum (`ActivityLog::ACTIONS`), pe lângă fraza deja compusă —
            // exact ca `Activity\ActivityLogResource`, care expune de mult `action` alături
            // de `actionLabel`. ATENȚIE: feed-ul NU mai alege semnalul vizual din `action` —
            // îl ia din `kind`, derivat server-side; `action` rămâne pe contract doar pentru
            // paritate cu celălalt ecran și pentru testele care se uită la valoarea brută.
            // `description` rămâne singura sursă a TEXTULUI.
            'action' => $this->action,
            // „Ce s-a întâmplat", derivat — `updated` acoperă deopotrivă o mutare de etapă,
            // o factură plătită și o editare de titlu (vezi `ActivityKind`). Feed-ul alege
            // iconul și tenta din ASTA, nu din `action`, ca rândurile de rutină să rămână
            // neutre și doar consecințele reale să iasă în evidență.
            'kind' => $kind,
            'description' => ActivityNarrative::describe($this->resource, $kind),
            // Numele entității atinse, ca al DOILEA câmp, nu interpolat în `description`:
            // fraza rămâne tradusă întreagă (FR-I18N-06), iar conținutul scris de
            // utilizator intră separat. `null` când entitatea nu mai există sau rândul
            // n-are subiect (login, export în masă).
            //
            // GDPR-02: citim numele CURENT al modelului, deci un contact anonimizat apare
            // cu placeholderul lui — feed-ul nu poate reînvia date șterse.
            'subjectName' => ActivityNarrative::subjectName($this->resource),
            // FR-TEN-04 — placeholder „(deactivated)" pe autorul unei acțiuni dacă
            // membership-ul lui în tenantul curent a fost dezactivat între timp.
            'actor' => DeactivatedMemberNames::label($this->user?->name, $this->user_id) ?? __('activity.entries.system_actor'),
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
