<?php

namespace App\Support\Lists;

use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Istoricul de mișcări al UNEI variante (FR-STOCK-03) — spre deosebire de
 * `AccountList`/`ProductList`, scopată prin constructor, nu printr-un filtru din URL:
 * ruta e `/variants/{variant}/stock/history`, deci varianta vine din segmentul de rută
 * (ADR-014 — parametrii tipizați ai controllerului), nu dintr-o cheie de filtru pe care
 * utilizatorul ar putea-o schimba liber (ca `account` pe `ContactList`).
 */
final class StockMovementList extends ResourceList
{
    public function __construct(private readonly Variant $variant) {}

    protected function filterKeys(): array
    {
        return ['reason', 'location', 'from', 'to'];
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
            'reason' => in_array($value, StockMovement::REASONS, true),
            'location' => Str::isUlid($value),
            'from', 'to' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value),
            default => true,
        };
    }

    protected function baseQuery(): Builder
    {
        return StockMovement::query()
            ->where('variant_id', $this->variant->getKey())
            ->with(['location:id,name', 'createdBy:id,name']);
    }

    protected function applyFilters(Builder $query, ListQuery $list, User $user): void
    {
        if (($reason = $list->filter('reason')) !== null) {
            $query->where('reason', $reason);
        }

        if (($location = $list->filter('location')) !== null) {
            $query->where('location_id', $location);
        }

        if (($from = $list->filter('from')) !== null) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if (($to = $list->filter('to')) !== null) {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }
    }
}
