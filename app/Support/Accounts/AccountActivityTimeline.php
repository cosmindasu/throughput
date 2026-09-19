<?php

namespace App\Support\Accounts;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\DealStageEvent;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * FR-CRM-04 — cronologia unificată a tab-ului „Activity" al unui cont: deals create,
 * tranziții de etapă (`deal_stage_events`), comenzi plasate și `activity_log` legate de
 * cont, cele mai recente ~30, descrescător.
 *
 * Patru surse eterogene, un singur array de ieșire — de aici clasa dedicată, nu patru
 * interogări cârpite direct în controller. Rândurile sunt array-uri simple, nu modele:
 * regula 1 din plan §1.2 (niciun model brut în Inertia) vizează expunerea necontrolată de
 * coloane, nu forma de transport, iar aici fiecare câmp e ales explicit.
 *
 * P2-002 — fiecare sursă e limitată la nivel SQL (`orderBy` + `limit(self::LIMIT)`),
 * nu doar în PHP după ce s-a adus tot istoricul: un cont vechi, cu mii de comenzi sau
 * evenimente de etapă, nu are voie să încarce integral doar ca să arunce aproape totul la
 * `take()` final. Sursa de `deal_stage_events` nu depinde de lista de deals de mai sus
 * (altfel limitarea acesteia din urmă ar ascunde tranziții recente ale unor deals mai
 * vechi) — interoghează direct prin `whereHas('deal', ...)`, pe contul curent.
 *
 * Linkurile spre deals sunt căi LITERALE (`/{workspace}/deals/{id}`), nu `route()`:
 * pachetul de Deals se dezvoltă în paralel, în alt branch (vezi AppLayout.tsx — aceeași
 * convenție, fără Ziggy). Comenzile nu au încă pagină de detaliu (Faza 3), deci `url` e
 * `null` pentru ele — un link mort ar fi mai rău decât text simplu.
 */
final class AccountActivityTimeline
{
    public const LIMIT = 30;

    /**
     * @return list<array{id: string, description: string, at: string|null, url: string|null}>
     */
    public static function build(Account $account): array
    {
        $workspace = app()->bound('tenant') ? app('tenant')->slug : null;

        $entries = collect();

        $account->deals()
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get(['id', 'title', 'created_at'])
            ->each(function (Deal $deal) use ($entries, $workspace): void {
                $entries->push([
                    'id' => 'deal-created:'.$deal->id,
                    'description' => "Deal created: {$deal->title}",
                    'at' => $deal->created_at?->toIso8601String(),
                    'url' => $workspace !== null ? "/{$workspace}/deals/{$deal->id}" : null,
                ]);
            });

        DealStageEvent::query()
            ->whereHas('deal', fn (Builder $query) => $query->where('account_id', $account->getKey()))
            ->with(['deal:id,title', 'toStage:id,name'])
            ->orderByDesc('changed_at')
            ->limit(self::LIMIT)
            ->get()
            ->each(function (DealStageEvent $event) use ($entries, $workspace): void {
                $title = $event->deal?->title ?? 'Deal';
                $stage = $event->toStage?->name ?? 'a new stage';

                $entries->push([
                    'id' => 'stage-event:'.$event->id,
                    'description' => "{$title} moved to {$stage}",
                    'at' => $event->changed_at?->toIso8601String(),
                    'url' => $workspace !== null && $event->deal_id !== null ? "/{$workspace}/deals/{$event->deal_id}" : null,
                ]);
            });

        $account->orders()
            // `placed_at` e nullable (comandă încă în draft) — `COALESCE` ține „cele mai
            // recente" corect fără să scoată din SQL rândurile fără dată de plasare.
            ->orderByRaw('coalesce(placed_at, created_at) desc')
            ->limit(self::LIMIT)
            ->get(['id', 'order_number', 'placed_at', 'created_at'])
            ->each(function (Order $order) use ($entries): void {
                $label = $order->order_number ?? ('#'.Str::substr($order->id, -8));

                $entries->push([
                    'id' => 'order-placed:'.$order->id,
                    'description' => "Order placed: {$label}",
                    'at' => ($order->placed_at ?? $order->created_at)?->toIso8601String(),
                    'url' => null,
                ]);
            });

        ActivityLog::query()
            ->where('auditable_type', Account::class)
            ->where('auditable_id', $account->id)
            ->orderByDesc('created_at')
            // Aceeași departajare ca în `DashboardController::recentActivity()` — `id` e
            // ULID, deci lexicografic în ordinea timpului. Fără ea, două intrări din aceeași
            // secundă apar în ordine arbitrară.
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'action', 'created_at'])
            ->each(function (ActivityLog $log) use ($entries): void {
                $entries->push([
                    'id' => 'activity:'.$log->id,
                    'description' => Str::headline($log->action),
                    'at' => $log->created_at?->toIso8601String(),
                    'url' => null,
                ]);
            });

        return $entries
            ->sortByDesc(fn (array $entry) => $entry['at'] ?? '')
            ->take(self::LIMIT)
            ->values()
            ->all();
    }
}
