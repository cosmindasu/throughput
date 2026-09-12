<?php

namespace App\Models\Scopes;

use App\Exceptions\TenantContextMissingException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Cheia de container care ține contextul.
     *
     * Deliberat `tenant.id` (scalar), nu `tenant` (modelul): singurul loc unde există un
     * model rezolvat e cererea HTTP (ResolveWorkspace). Joburile și comenzile primesc un
     * ULID, iar dacă scope-ul ar cere modelul, fiecare job ar plăti un SELECT în plus —
     * sau, mai rău, ar arunca TenantContextMissingException deși contextul RLS e setat
     * corect. `TenantContext` leagă mereu `tenant.id`; `ResolveWorkspace` leagă în plus
     * `tenant` (modelul), pentru interfață.
     */
    public const CONTAINER_KEY = 'tenant.id';

    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(
            $model->getTable().'.tenant_id',
            self::requireCurrentTenantId($model::class)
        );
    }

    public static function currentTenantId(): ?string
    {
        return app()->bound(self::CONTAINER_KEY) ? app(self::CONTAINER_KEY) : null;
    }

    /**
     * Guard explicit (ADR-003, secțiunea Consecințe): eroare inteligibilă la dezvoltare,
     * nu un query tăcut care returnează tot sau nimic. RLS ar cădea oricum închis
     * (zero rânduri), dar „rândul pur și simplu nu există" e exact mesajul opac pe care
     * ADR-003 îl numește costul stratului 2 — stratul 1 e cel care trebuie să spună de ce.
     */
    public static function requireCurrentTenantId(?string $modelClass = null): string
    {
        return self::currentTenantId() ?? throw new TenantContextMissingException($modelClass);
    }
}
