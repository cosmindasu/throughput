<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legătura user ↔ tenant (§6.4). Tabela cu singura politică RLS neuniformă din aplicație
 * (ADR-014, pct. 2) — vezi migrația pentru argumentație.
 */
#[Fillable(['user_id', 'status', 'invitation_token', 'invitation_expires_at'])]
class Membership extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING = 'pending';

    /** BR-TEN-04: dezactivarea nu e un DELETE fizic — rândul rămâne, pentru istoric. */
    public const STATUS_DEACTIVATED = 'deactivated';

    protected function casts(): array
    {
        return [
            'invitation_expires_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * SINGURUL loc din aplicație în care ocolirea global scope-ului Eloquent e legitimă
     * (ADR-014, pct. 2; plan §7.2). Un test arhitectural asertează că `withoutGlobalScope`
     * nu apare nicăieri altundeva — dacă ai ajuns aici căutând cum să ocolești scope-ul
     * pentru altceva, răspunsul e nu.
     *
     * Interogarea „în ce organizații sunt membru" e cross-tenant prin natura ei: rulează
     * după autentificare, dar înainte să se știe workspace-ul. În bază o lasă să treacă
     * politica proprie a tabelei, pe `app.user_id`; în Eloquent, linia de mai jos.
     *
     * @return Collection<int, Membership>
     */
    public static function forCurrentUserAcrossTenants(string $userId): Collection
    {
        return static::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with('tenant')
            ->where('user_id', $userId)
            ->where('status', self::STATUS_ACTIVE)
            ->join('tenants', 'tenants.id', '=', 'memberships.tenant_id')
            ->orderBy('tenants.name')
            ->select('memberships.*')
            ->get();
    }
}
