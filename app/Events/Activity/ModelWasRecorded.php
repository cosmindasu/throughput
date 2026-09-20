<?php

namespace App\Events\Activity;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * ADR-007 — „Eloquent model observers ... dispatch-uiesc un event; un queued listener scrie
 * asincron rândul." Acest event e podul dintre cele două: `App\Observers\ActivityLogObserver`
 * îl umple SINCRON, în firul cererii (unde `request()->ip()`/`userAgent()` există), cu
 * SCALARI simpli — niciun model Eloquent — exact regula de la §6.3 pentru orice payload care
 * traversează coada: un model serializat ar re-hidrata din DB pe worker, sub alt context de
 * tenant decât cel din request, dacă workerul preia jobul cu întârziere.
 *
 * `tenantId` explicit, nu dedus din container la momentul procesării: `App\Services\Tenancy\
 * TenantContext::run()` din listener are nevoie de el ca argument, nu ca stare ambientă
 * (ADR-014 pct. 4, „joburi de tenant primesc tenantId scalar").
 */
final class ModelWasRecorded
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $userId,
        public readonly string $action,
        public readonly string $auditableType,
        public readonly string $auditableId,
        public readonly ?array $oldValues,
        public readonly ?array $newValues,
        public readonly string $ipAddress,
        public readonly string $userAgent,
    ) {}
}
