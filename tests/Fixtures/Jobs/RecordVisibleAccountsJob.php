<?php

namespace Tests\Fixtures\Jobs;

use App\Jobs\Middleware\ApplyTenantContextToJob;
use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Job de tenant, scris exact după regula obligatorie din plan §7.2: constructorul
 * primește `string $tenantId` (scalar), iar contextul se restaurează prin middleware-ul
 * de job — niciodată cu `set_config` scris de mână în `handle()`.
 */
class RecordVisibleAccountsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $tenantId) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new ApplyTenantContextToJob];
    }

    public function handle(): void
    {
        Cache::put('visible-accounts:'.$this->tenantId, Account::query()->pluck('name')->all());
    }
}
