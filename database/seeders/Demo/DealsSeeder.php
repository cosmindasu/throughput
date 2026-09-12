<?php

namespace Database\Seeders\Demo;

use App\Models\Deal;
use App\Models\DealStageEvent;
use App\Models\Tenant;
use Database\Factories\DealFactory;
use Database\Seeders\Support\ActivityLogRecorder;
use Database\Seeders\Support\ChunkedWriter;
use Database\Seeders\Support\DemoClock;
use Database\Seeders\Support\Rand;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Deals pe toate etapele (nu "totul Won", specs.md §21.2) + `deal_stage_events` ca istoric
 * complet de tranziții (§9.1) — append-only, un rând nou per tranziție, niciodată un UPDATE.
 * ~55% dintre conturi primesc o oportunitate.
 */
final class DealsSeeder
{
    /**
     * @param  list<array{id: string, name: string, position: int, is_won: bool, is_lost: bool, probability: int}>  $stages
     * @param  array{accounts: list<array>, contacts_by_account: array<string, list<string>>}  $accountsResult
     * @param  array{owner_id: string, demo_agent_id: ?string, pool: list<array{id: string, role: string}>}  $staff
     * @return array{won_deal_ids_by_account: array<string, list<string>>}
     */
    public function run(Tenant $tenant, array $config, string $pipelineId, array $stages, array $accountsResult, array $staff, ?Command $command, ActivityLogRecorder $activityLog): array
    {
        $accounts = $accountsResult['accounts'];
        if ($accounts === []) {
            return ['won_deal_ids_by_account' => []];
        }

        $target = (int) round(count($accounts) * 0.55);
        $dealFactory = new DealFactory;
        $writer = new ChunkedWriter(Deal::class, 1000, $command, 'Deals', $target);
        $eventWriter = new ChunkedWriter(DealStageEvent::class, 1000, $command, 'Deal stage events', (int) ($target * 1.8));

        $ownerIds = array_column($staff['pool'], 'id');

        $openStages = array_values(array_filter($stages, fn ($s) => ! $s['is_won'] && ! $s['is_lost']));
        usort($openStages, fn ($a, $b) => $a['position'] <=> $b['position']);
        $wonStage = current(array_filter($stages, fn ($s) => $s['is_won'])) ?: null;
        $lostStage = current(array_filter($stages, fn ($s) => $s['is_lost'])) ?: null;

        shuffle($accounts);
        $accountCount = count($accounts);
        $wonByAccount = [];

        for ($i = 0; $i < $target; $i++) {
            $account = $accounts[$i % $accountCount];
            $dealId = (string) Str::ulid();
            $ownerId = $ownerIds[array_rand($ownerIds)];

            $createdAt = DemoClock::historicalDate(24);
            if ($createdAt->lessThan($account['created_at'])) {
                $createdAt = Carbon::parse($account['created_at'])->addDays(random_int(0, 5));
            }

            $outcome = Rand::weightedKey(['won' => 35, 'lost' => 15, 'open' => 50]);

            $events = [];
            $currentTime = $createdAt->copy();
            $stageIndex = 0;
            $toStage = $openStages[0];
            $events[] = ['from' => null, 'to' => $toStage, 'at' => $currentTime->copy()];

            if ($outcome === 'open') {
                $hops = random_int(0, min(2, count($openStages) - 1));
                for ($h = 0; $h < $hops; $h++) {
                    $stageIndex++;
                    $fromStage = $toStage;
                    $toStage = $openStages[$stageIndex];
                    $currentTime = DemoClock::shortlyAfter($currentTime, 48, 24 * 21);
                    $events[] = ['from' => $fromStage, 'to' => $toStage, 'at' => $currentTime->copy()];
                }
            }

            $finalStage = $toStage;
            $status = Deal::STATUS_OPEN;
            $lostReason = null;

            if ($outcome === 'won' && $wonStage !== null) {
                $currentTime = DemoClock::shortlyAfter($currentTime, 48, 24 * 30);
                $events[] = ['from' => $toStage, 'to' => $wonStage, 'at' => $currentTime->copy()];
                $finalStage = $wonStage;
                $status = Deal::STATUS_WON;
            } elseif ($outcome === 'lost' && $lostStage !== null) {
                $currentTime = DemoClock::shortlyAfter($currentTime, 48, 24 * 30);
                $events[] = ['from' => $toStage, 'to' => $lostStage, 'at' => $currentTime->copy()];
                $finalStage = $lostStage;
                $status = Deal::STATUS_LOST;
                $lostReason = Rand::weightedKey(['price' => 40, 'competition' => 30, 'timing' => 20, 'other' => 10]);
            }

            $value = ($status === Deal::STATUS_WON || Rand::bool(70))
                ? Rand::money(850, 185000)
                : null;

            $contactId = $accountsResult['contacts_by_account'][$account['id']][0] ?? null;

            $row = $dealFactory->definition();
            $row['id'] = $dealId;
            $row['tenant_id'] = $tenant->id;
            $row['account_id'] = $account['id'];
            $row['primary_contact_id'] = $contactId;
            $row['pipeline_id'] = $pipelineId;
            $row['stage_id'] = $finalStage['id'];
            $row['owner_user_id'] = $ownerId;
            $row['value'] = $value;
            $row['status'] = $status;
            $row['lost_reason'] = $lostReason;
            $row['created_by'] = $ownerId;
            $row['created_at'] = $createdAt;
            $row['updated_at'] = $currentTime;

            $writer->push($row);
            $activityLog->record($tenant->id, $ownerId, 'created', Deal::class, $dealId, $createdAt);

            $previousAt = null;
            $multipleEvents = count($events) > 1;
            foreach ($events as $event) {
                $duration = $previousAt !== null ? $previousAt->diffInSeconds($event['at']) : null;

                $eventWriter->push([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $tenant->id,
                    'deal_id' => $dealId,
                    'from_stage_id' => $event['from']['id'] ?? null,
                    'to_stage_id' => $event['to']['id'],
                    'changed_by' => $ownerId,
                    'changed_at' => $event['at'],
                    'duration_in_previous_stage_seconds' => $duration,
                ]);

                if ($multipleEvents && $event['from'] !== null) {
                    $activityLog->record($tenant->id, $ownerId, 'updated', Deal::class, $dealId, $event['at'], null, ['stage' => $event['to']['name']]);
                }

                $previousAt = $event['at'];
            }

            if ($status === Deal::STATUS_WON) {
                $wonByAccount[$account['id']][] = $dealId;
            }
        }

        $writer->flush();
        $eventWriter->flush();
        $activityLog->flush();

        return ['won_deal_ids_by_account' => $wonByAccount];
    }
}
