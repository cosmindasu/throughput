<?php

namespace App\Support\Reports;

use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Îngustarea ABAC din §7.4: „Agent — R (doar cele unde e destinatar)". NU e o permisiune
 * separată (§7.5, la fel ca `Permissions::restrictedToOwnRecords()` pentru conturi/deals) —
 * `reports.view` dă dreptul de citire, această clasă îl îngustează la rândurile unde
 * adresa de email a Agentului apare în `recipients`.
 *
 * Comparație case-insensitive: `recipients` e populat liber, prin formular, de Owner/Manager
 * (§16.1 — „jsonb array, emailuri"), nu neapărat cu aceeași capitalizare cu care utilizatorul
 * și-a înregistrat contul. Normalizat la scriere (`StoreReportRequest`/`UpdateReportRequest`)
 * ȘI la citire aici, ca cele două părți să nu poată diverge silențios.
 */
final class ReportRecipients
{
    public static function normalize(array $emails): array
    {
        return array_values(array_unique(array_map(
            fn (string $email): string => Str::lower(trim($email)),
            $emails,
        )));
    }

    public static function isRecipient(ReportDefinition $report, User $user): bool
    {
        return in_array(Str::lower($user->email), self::normalize($report->recipients ?? []), true);
    }

    /**
     * Aplicată în `ReportController::index()` — Agentul nu vede în listă rapoartele unde
     * nu e destinatar, nu doar pe pagina de detaliu (cerință explicită a task-ului).
     */
    public static function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! Permissions::restrictedToOwnRecords($user)) {
            return $query;
        }

        return $query->whereJsonContains('recipients', Str::lower($user->email));
    }
}
