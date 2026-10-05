<?php

namespace Database\Seeders\Demo;

use App\Models\Deal;
use App\Models\DealStageEvent;
use App\Models\Tenant;
use Database\Factories\DealFactory;
use Database\Seeders\Support\ActivityLogRecorder;
use Database\Seeders\Support\ChunkedWriter;
use Database\Seeders\Support\DealTitle;
use Database\Seeders\Support\DemoClock;
use Database\Seeders\Support\DemoId;
use Database\Seeders\Support\Rand;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deals pe toate etapele (nu "totul Won", specs.md §21.2) + `deal_stage_events` ca istoric
 * complet de tranziții (§9.1) — append-only, un rând nou per tranziție, niciodată un UPDATE.
 * ~55% dintre conturi primesc o oportunitate.
 */
final class DealsSeeder
{
    /**
     * @param  list<array{id: string, name: string, position: int, is_won: bool, is_lost: bool, probability: int}>  $stages
     * @param  list<string>  $categories  categoriile catalogului, pentru titluri (`DealTitle`)
     * @param  array{accounts: list<array>, contacts_by_account: array<string, list<string>>}  $accountsResult
     * @param  array{owner_id: string, demo_agent_id: ?string, pool: list<array{id: string, role: string}>}  $staff
     * @return array{won_deal_ids_by_account: array<string, list<string>>}
     */
    public function run(Tenant $tenant, array $config, string $pipelineId, array $stages, array $categories, array $accountsResult, array $staff, ?Command $command, ActivityLogRecorder $activityLog): array
    {
        $accounts = $accountsResult['accounts'];
        if ($accounts === []) {
            return ['won_deal_ids_by_account' => []];
        }

        $target = (int) round(count($accounts) * 0.55);
        $dealFactory = new DealFactory;
        $writer = new ChunkedWriter(Deal::class, 1000, $command, 'Deals', $target);
        $eventWriter = (new ChunkedWriter(DealStageEvent::class, 1000, $command, 'Deal stage events', (int) ($target * 1.8)))
            ->dependsOn($writer);   // FK deal_stage_events.deal_id

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
            $dealId = DemoId::next();
            $ownerId = $ownerIds[array_rand($ownerIds)];

            // `created_at` al contului e timestamp, nu Carbon — vezi AccountsAndContactsSeeder.
            $createdAt = DemoClock::historicalDate(24);
            if ($createdAt->getTimestamp() < $account['created_at']) {
                // `DemoClock::shortlyAfter`, nu `addDays()`: funcția plafonează la prezent.
                // Un cont creat alaltăieri plus „0-5 zile" dă o dată din VIITOR, iar comanda
                // ajunge în „Recent activity" datată peste o săptămână.
                $createdAt = DemoClock::shortlyAfter(Carbon::createFromTimestamp($account['created_at'], 'UTC'), 0, 24 * 5);
            }

            $outcome = Rand::weightedKey(['won' => 35, 'lost' => 15, 'open' => 50]);

            $events = [];
            $currentTime = $createdAt->copy();
            $stageIndex = 0;
            $toStage = $openStages[0];
            $events[] = ['from' => null, 'to' => $toStage, 'at' => $currentTime->copy()];

            // Drumul prin etapele DESCHISE, pentru TOATE afacerile — nu doar pentru cele
            // rămase deschise, cum era până acum. Varianta anterioară muta doar afacerile
            // deschise, cu cel mult două salturi, iar cele câștigate/pierdute săreau direct
            // din prima etapă în cea terminală. Trei consecințe vizibile, toate în ecrane
            // diferite, toate arătând ca defecte ale PRODUSULUI:
            //
            //  - ultima etapă deschisă („Negotiation", a patra) nu era atinsă NICIODATĂ:
            //    kanbanul avea permanent o coloană goală;
            //  - raportul „Deal velocity" arăta 1375 → 451 → 211 → 0 → Won 509, adică o
            //    pâlnie în care mai multe afaceri ies decât intră pe penultima treaptă;
            //  - nicio afacere nu PĂRĂSEA vreodată „Proposal Sent", deci durata medie în
            //    etapă era nulă acolo, iar graficul de timp avea doar două bare din patru.
            //
            // Afacerile câștigate ajung mai departe decât cele pierdute, iar cele încă
            // deschise sunt împrăștiate pe tot traseul: un pipeline real are mai multe
            // oportunități la început decât la sfârșit.
            $lastOpenStage = count($openStages) - 1;
            $hops = match ($outcome) {
                'won' => random_int(max($lastOpenStage - 1, 0), $lastOpenStage),
                'lost' => random_int(0, $lastOpenStage),
                default => Rand::weightedKey([0 => 40, 1 => 30, 2 => 20, 3 => 10]),
            };

            for ($h = 0; $h < min((int) $hops, $lastOpenStage); $h++) {
                $stageIndex++;
                $fromStage = $toStage;
                $toStage = $openStages[$stageIndex];
                $currentTime = DemoClock::shortlyAfter($currentTime, 48, 24 * 21);
                $events[] = ['from' => $fromStage, 'to' => $toStage, 'at' => $currentTime->copy()];
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
            // Titlul se compune din categoriile ACESTUI tenant, nu se ia din cele opt șiruri
            // fixe ale factory-ului: acolo, la 1.375 de afaceri, fiecare titlu se repeta de
            // ~172 de ori și se vedeau șase identice pe un singur ecran de listă.
            $row['title'] = DealTitle::compose($categories, $createdAt);
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
                    'id' => DemoId::next(),
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
