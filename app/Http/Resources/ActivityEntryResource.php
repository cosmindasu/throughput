<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use App\Models\Membership;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Feed de activitate recentă a dashboard-ului (FR-DEMO-01) — ultimele 10 din
 * `activity_log`. `ActivityLog` nu are o coloană `description`: se compune aici din
 * `action` + `auditable_type`, o singură dată, nu recalculată în React (plan §1.2 regula 1).
 *
 * @mixin ActivityLog
 */
class ActivityEntryResource extends JsonResource
{
    /**
     * @return array{id: string, description: string, actor: string, at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description(),
            // FR-TEN-04 — placeholder „(deactivated)" pe autorul unei acțiuni dacă
            // membership-ul lui în tenantul curent a fost dezactivat între timp.
            'actor' => DeactivatedMemberNames::label($this->user?->name, $this->user_id) ?? 'System',
            'at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function description(): string
    {
        $subject = $this->auditable_type ? Str::headline(class_basename($this->auditable_type)) : 'record';

        // US-TEN-03 — `MembersController::applyDeactivation()` scrie `action = 'updated'`
        // pe un `Membership` (enum-ul Postgres al coloanei n-are o valoare dedicată,
        // §17.1): „Updated Membership" ar fi corect, dar opac. Un singur caz special,
        // înaintea switch-ului generic.
        if ($this->auditable_type === Membership::class
            && ($this->new_values['status'] ?? null) === Membership::STATUS_DEACTIVATED) {
            return 'Deactivated a member';
        }

        return match ($this->action) {
            'created' => "Created {$subject}",
            'updated' => "Updated {$subject}",
            'deleted' => "Deleted {$subject}",
            'login' => 'Logged in',
            'login_failed' => 'Failed login attempt',
            'exported' => "Exported {$subject}",
            'imported' => "Imported {$subject}",
            'bulk_action' => "Performed a bulk action on {$subject}",
            'role_changed' => 'Changed a member role',
            default => Str::headline($this->action),
        };
    }
}
