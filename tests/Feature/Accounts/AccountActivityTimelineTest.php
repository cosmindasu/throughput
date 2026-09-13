<?php

namespace Tests\Feature\Accounts;

use App\Models\Account;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Services\Tenancy\TenantContext;
use App\Support\Accounts\AccountActivityTimeline;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P2-002 — fiecare sursă a cronologiei (`deals`, `deal_stage_events`, `orders`,
 * `activity_log`) trebuie limitată la nivel SQL (`orderBy` + `limit`), nu doar în PHP
 * după ce s-a adus tot istoricul contului. Un test care ar verifica doar numărul final de
 * intrări (`take(30)`) ar trece verde și pe varianta veche, care aducea totul în memorie —
 * de aceea testul de aici verifică FORMA interogărilor, nu doar rezultatul.
 */
class AccountActivityTimelineTest extends TestCase
{
    public function test_every_source_query_carries_its_own_sql_level_limit(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $account = TenantContext::run($tenant, function () use ($owner): Account {
            $account = (new AccountFactory)->create(['created_by' => $owner->getKey()]);

            $pipeline = Pipeline::query()->create(['name' => 'Standard']);
            $stage = Stage::query()->create(['pipeline_id' => $pipeline->getKey(), 'name' => 'Qualification', 'position' => 1]);

            // Peste `AccountActivityTimeline::LIMIT`, ca un fetch fără LIMIT să fie
            // observabil în interogare, nu doar teoretic pe un cont cu puține rânduri.
            foreach (range(1, AccountActivityTimeline::LIMIT + 5) as $i) {
                $deal = new Deal([
                    'account_id' => $account->getKey(),
                    'pipeline_id' => $pipeline->getKey(),
                    'stage_id' => $stage->getKey(),
                    'owner_user_id' => $owner->getKey(),
                    'title' => "Deal {$i}",
                    'status' => Deal::STATUS_OPEN,
                ]);
                $deal->created_by = $owner->getKey();
                $deal->save();

                $order = new Order([
                    'account_id' => $account->getKey(),
                    'owner_user_id' => $owner->getKey(),
                    'status' => Order::STATUS_CONFIRMED,
                    'grand_total' => 100,
                ]);
                $order->created_by = $owner->getKey();
                $order->save();
            }

            return $account;
        });
        $this->clearDatabaseTenantContext();

        $sourceQueries = [];

        DB::listen(function ($query) use (&$sourceQueries): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'from "deals"')
                || str_contains($sql, 'from "deal_stage_events"')
                || str_contains($sql, 'from "orders"')
                || str_contains($sql, 'from "activity_log"')) {
                $sourceQueries[] = $sql;
            }
        });

        TenantContext::run($tenant, fn () => AccountActivityTimeline::build($account->fresh()));

        $this->assertNotEmpty($sourceQueries, 'Interogările surselor cronologiei nu au fost prinse de listener — testul nu verifică nimic.');

        foreach ($sourceQueries as $sql) {
            $this->assertStringContainsString('limit', $sql, "Interogarea NU are LIMIT la nivel SQL: {$sql}");
        }
    }
}
