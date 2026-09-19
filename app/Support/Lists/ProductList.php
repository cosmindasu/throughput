<?php

namespace App\Support\Lists;

use App\Models\Product;
use App\Models\User;
use App\Support\ListQuery;
use App\Support\Stock\LowStockRule;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lista de produse — `Products/Index` (specs.md §10, Pachetul A punctul 1). Fără filtru
 * implicit de owner: produsele nu au proprietar (spre deosebire de `AccountList`/
 * `DealList`), Agentul și Viewer-ul văd întotdeauna tot catalogul tenantului (§7.4 —
 * doar `R`, fără restrângere ABAC).
 */
final class ProductList extends ResourceList
{
    protected function filterKeys(): array
    {
        return ['q', 'category', 'status'];
    }

    protected function sortableColumns(): array
    {
        return ['name', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function accepts(string $key, string $value): bool
    {
        return match ($key) {
            'status' => in_array($value, ['active', 'inactive'], true),
            default => true,
        };
    }

    /**
     * `lowStockVariantsCount` (FR-STOCK-02, task brief punctul 4) — subinterogare agregată
     * CORELATĂ pe `variants.product_id = products.id`, calculată în ACEEAȘI interogare de
     * listă, fără `withCount`/N+1 și fără încărcarea variantelor în PHP. Regula „low" e
     * scrisă o singură dată, în `App\Support\Stock\LowStockRule`.
     */
    protected function baseQuery(): Builder
    {
        return Product::query()
            ->withCount('variants')
            ->addSelect(['low_stock_variants_count' => LowStockRule::lowVariantsCountSubquery()]);
    }

    protected function applyFilters(Builder $query, ListQuery $list, User $user): void
    {
        if (($search = $list->filter('q')) !== null) {
            $query->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }

        if (($category = $list->filter('category')) !== null) {
            $query->where('category', $category);
        }

        if (($status = $list->filter('status')) !== null) {
            $query->where('is_active', $status === 'active');
        }
    }
}
