<?php

namespace App\Jobs\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;

/**
 * ADR-014, pct. 4-5. Se declară PER JOB, în `middleware()`, niciodată global:
 *
 *   - joburile de tenant îl declară și primesc `string $tenantId` în constructor
 *     (scalar, niciodată un model Eloquent — capcana de serializare din §6.3);
 *   - joburile cu I/O extern (curierat, Chromium, rapoarte) NU îl declară: ar ține
 *     apelul extern într-o tranzacție deschisă, exact ce a scos ADR-013 din cererea
 *     HTTP. Ele cheamă TenantContext::run() de două ori, cu apelul între tranzacții.
 */
class ApplyTenantContextToJob
{
    public function handle(object $job, Closure $next): void
    {
        TenantContext::run($job->tenantId, fn () => $next($job));
    }
}
