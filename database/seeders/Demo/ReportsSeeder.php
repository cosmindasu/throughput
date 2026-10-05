<?php

namespace Database\Seeders\Demo;

use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\Tenant;
use App\Models\User;
use App\Support\JobErrorMessage;
use App\Support\Permissions;
use App\Support\Reports\BuiltInReports;
use Database\Seeders\Support\DemoId;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rapoartele programate (specs.md §16) — singurul modul pe care setul demo îl lăsa COMPLET
 * gol: niciun seeder nu scria `report_definitions`, deci cine deschidea „Reports" într-un
 * demo vedea o pagină goală cu un buton. Pe un modul care e argument de vânzare, asta nu
 * arată „nimic configurat încă", arată „nu face nimic".
 *
 * ## Ce scrie, și de ce exact asta
 *
 * Trei definiții, alese ca să acopere stările pe care lista le poate arăta — activ și oprit,
 * trei frecvențe, trei formate — nu ca să umple tabelul:
 *
 *  1. **Weekly deal velocity**, PDF, activ. Istoric de șase rulări săptămânale, dintre care
 *     UNA eșuată. Un istoric numai verde arată fabricat, și — mai important — ascunde un
 *     ecran întreg (randarea mesajului de eroare) pe care cineva care evaluează produsul
 *     vrea tocmai să-l vadă funcționând. Eșecul NU e cel mai recent, deci statusul din listă
 *     rămâne „success".
 *  2. **Monthly inventory valuation**, XLSX, activ. Are și un Agent printre destinatari —
 *     îngustarea ABAC din §7.4 („Agentul vede doar rapoartele unde e destinatar",
 *     `ReportRecipients::scopeVisibleTo`) n-are ce demonstra dacă niciun raport nu-l
 *     listează.
 *  3. **Daily deal velocity**, CSV, OPRIT. Zece rulări zilnice care se termină acum trei
 *     săptămâni — exact forma pe care o are un raport pus pe pauză.
 *
 * ## Numerele nu sunt inventate
 *
 * `row_count` se citește din raportul REAL (`BuiltInReports::resolve(...)->rows()`), o dată
 * per tip și per tenant. Pagina de detaliu randează previzualizarea LIVE la fiecare vizită
 * (`ReportController::builtInPreview()`), deci un `row_count` ales din burtă ar fi contrazis
 * pe loc de tabelul de dedesubt.
 *
 * `file_path` rămâne `null` peste tot: nu există fișiere generate, iar `Reports/Show` ascunde
 * „Download" fără el (`run.hasFile`). Un link care duce la 404 ar fi mai rău decât absența lui.
 *
 * `error_message` trece prin `JobErrorMessage::encode()`, ca orice eșec scris de un job real
 * (I18N-03): un literal englez pus direct în coloană ar rămâne englez și în franceză.
 */
final class ReportsSeeder
{
    /**
     * @param  array{owner_id: string, demo_agent_id: ?string, pool: list<array{id: string, role: string}>}  $staff
     */
    public function run(Tenant $tenant, array $staff, ?Command $command): void
    {
        $command?->getOutput()->writeln('  <fg=cyan>›</> Scheduled reports');

        $ownerId = $staff['owner_id'];
        $emails = User::query()->whereIn('id', array_column($staff['pool'], 'id'))->pluck('email', 'id');
        $ownerEmail = $emails->get($ownerId);

        if ($ownerEmail === null) {
            return;
        }

        $managerId = $this->firstWithRole($staff, Permissions::MANAGER);
        $agentId = $staff['demo_agent_id'] ?? $this->firstWithRole($staff, Permissions::AGENT);

        $recipients = array_values(array_unique(array_filter([$ownerEmail, $emails->get($managerId)])));
        $withAgent = array_values(array_unique(array_filter([...$recipients, $emails->get($agentId)])));

        // O singură dată per tip: `rows()` al valorificării de stoc citește toate nivelurile
        // de inventar cu relațiile lor, deci nu se reapelează per rulare.
        $rowCount = [
            ReportDefinition::TYPE_DEAL_VELOCITY => count(BuiltInReports::resolve(ReportDefinition::TYPE_DEAL_VELOCITY)->rows()),
            ReportDefinition::TYPE_INVENTORY_VALUATION => count(BuiltInReports::resolve(ReportDefinition::TYPE_INVENTORY_VALUATION)->rows()),
        ];

        $weekly = $this->definition($tenant, $ownerId, [
            'name' => 'Weekly deal velocity',
            'report_type' => ReportDefinition::TYPE_DEAL_VELOCITY,
            'format' => ReportDefinition::FORMAT_PDF,
            'schedule_frequency' => ReportDefinition::FREQUENCY_WEEKLY,
            'schedule_time' => '07:00:00',
            'schedule_day' => 1,                 // luni
            'recipients' => $recipients,
            'is_active' => true,
            'created_at' => now()->subMonths(4),
        ]);

        $monthly = $this->definition($tenant, $ownerId, [
            'name' => 'Monthly inventory valuation',
            'report_type' => ReportDefinition::TYPE_INVENTORY_VALUATION,
            'format' => ReportDefinition::FORMAT_XLSX,
            'schedule_frequency' => ReportDefinition::FREQUENCY_MONTHLY,
            'schedule_time' => '06:30:00',
            'schedule_day' => 1,
            'recipients' => $withAgent,
            'is_active' => true,
            'created_at' => now()->subMonths(3),
        ]);

        $paused = $this->definition($tenant, $ownerId, [
            'name' => 'Daily deal velocity',
            'report_type' => ReportDefinition::TYPE_DEAL_VELOCITY,
            'format' => ReportDefinition::FORMAT_CSV,
            'schedule_frequency' => ReportDefinition::FREQUENCY_DAILY,
            'schedule_time' => '08:00:00',
            'schedule_day' => null,
            'recipients' => $recipients,
            'is_active' => false,
            'created_at' => now()->subMonths(5),
        ]);

        // Rulările se scriu de la cea mai VECHE la cea mai nouă: `latestRun` e
        // `ofMany('created_at', 'max')`, iar istoricul se ordonează după `id` — ambele cer ca
        // ULID-urile (monoton crescătoare la generare) să urmeze ordinea cronologică.
        $this->runsFor($tenant, $weekly, $rowCount[ReportDefinition::TYPE_DEAL_VELOCITY], 6, fn (int $i) => now()->subWeeks(6 - $i)->next(Carbon::MONDAY)->setTime(7, 0, 0), failedAt: 2);
        // `startOfMonth()` ÎNAINTE de `subMonths()`: invers, pe 31 mai, „acum 3 luni" și
        // „acum 2 luni" cad amândouă pe 1 martie (28/31 februarie nu există), deci istoricul
        // arăta două rulări la aceeași secundă și una lipsă. Aceeași capcană reparată în
        // `DashboardController` — plecând din ziua 1, nicio lună nu dă pe dinafară.
        $this->runsFor($tenant, $monthly, $rowCount[ReportDefinition::TYPE_INVENTORY_VALUATION], 3, fn (int $i) => now()->startOfMonth()->subMonths(3 - $i)->setTime(6, 30, 0));
        $this->runsFor($tenant, $paused, $rowCount[ReportDefinition::TYPE_DEAL_VELOCITY], 10, fn (int $i) => now()->subDays(31 - $i)->setTime(8, 0, 0));
    }

    /** @param array<string, mixed> $attributes */
    private function definition(Tenant $tenant, string $ownerId, array $attributes): string
    {
        $id = DemoId::next();

        DB::table('report_definitions')->insert([
            ...$attributes,
            'id' => $id,
            'tenant_id' => $tenant->id,
            'recipients' => json_encode($attributes['recipients']),
            'created_by' => $ownerId,
            'updated_at' => $attributes['created_at'],
        ]);

        return $id;
    }

    /**
     * @param  callable(int): Carbon  $at  momentul rulării `$i`, de la cea mai veche (0)
     * @param  int|null  $failedAt  indexul rulării eșuate, dacă există — niciodată ultimul
     */
    private function runsFor(Tenant $tenant, string $definitionId, int $rowCount, int $count, callable $at, ?int $failedAt = null): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $startedAt = $at($i);

            // O rulare viitoare n-ar fi un istoric. Se poate întâmpla la marginea ferestrei
            // (`next(MONDAY)` sare peste azi dacă azi E luni), deci se taie aici, nu se
            // presupune că nu apare.
            if ($startedAt->greaterThan(now())) {
                continue;
            }

            $failed = $i === $failedAt;

            $rows[] = [
                'id' => DemoId::next(),
                'tenant_id' => $tenant->id,
                'report_definition_id' => $definitionId,
                'status' => $failed ? ReportRun::STATUS_FAILED : ReportRun::STATUS_SUCCESS,
                'started_at' => $startedAt,
                'finished_at' => $startedAt->copy()->addSeconds(random_int(3, 14)),
                // Fără fișiere reale: `Reports/Show` ascunde „Download" pe `hasFile` fals,
                // ceea ce e mai bun decât un link către 404.
                'file_path' => null,
                'row_count' => $failed ? null : $rowCount,
                'error_message' => $failed ? JobErrorMessage::encode('job_errors.report.generation_failed') : null,
                'triggered_by' => ReportRun::TRIGGERED_BY_SCHEDULER,
                'created_at' => $startedAt,
            ];
        }

        if ($rows !== []) {
            DB::table('report_runs')->insert($rows);
        }
    }

    /** @param array{pool: list<array{id: string, role: string}>} $staff */
    private function firstWithRole(array $staff, string $role): ?string
    {
        foreach ($staff['pool'] as $member) {
            if ($member['role'] === $role) {
                return $member['id'];
            }
        }

        return null;
    }
}
