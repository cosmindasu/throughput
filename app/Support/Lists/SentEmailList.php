<?php

namespace App\Support\Lists;

use App\Models\SentEmail;
use App\Models\User;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Settings → Sent Emails (BR-DEMO-02, specs.md §22.3) — jurnal fără nicio îngustare de
 * proprietate: rândurile n-au un „owner" (spre deosebire de Accounts/Deals). Orice
 * utilizator cu drept de acces la ecran (`sent_emails.view`, gardat în
 * `SentEmailController`/`SentEmailPolicy`) vede TOATE rândurile tenantului curent — global
 * scope + RLS (migrația) fac izolarea; lista doar filtrează/sortează, ca restul listelor
 * (plan §1.2 regula 6).
 */
final class SentEmailList extends ResourceList
{
    /** @var list<string> */
    public const STATUSES = [
        SentEmail::STATUS_DELIVERED,
        SentEmail::STATUS_INTERCEPTED,
        SentEmail::STATUS_PARTIAL,
        SentEmail::STATUS_FAILED,
    ];

    protected function filterKeys(): array
    {
        return ['status'];
    }

    protected function sortableColumns(): array
    {
        return ['created_at'];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    protected function accepts(string $key, string $value): bool
    {
        return match ($key) {
            'status' => in_array($value, self::STATUSES, true),
            default => true,
        };
    }

    protected function baseQuery(): Builder
    {
        return SentEmail::query();
    }

    protected function applyFilters(Builder $query, ListQuery $list, User $user): void
    {
        if (($status = $list->filter('status')) !== null) {
            $query->where('status', $status);
        }
    }
}
