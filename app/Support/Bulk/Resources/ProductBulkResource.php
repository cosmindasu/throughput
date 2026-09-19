<?php

namespace App\Support\Bulk\Resources;

use App\Models\Product;
use App\Models\User;
use App\Support\Bulk\BulkWritableResource;
use App\Support\Lists\ProductList;
use Illuminate\Database\Eloquent\Builder;

/**
 * Produse — §13.5 (preț în masă, activare/dezactivare). Selecția pe `Products/Index` se
 * face pe PRODUSE (`ProductRow`), nu pe variante — id-urile chunk-uite aici sunt id-uri de
 * `products`; `App\Actions\Bulk\UpdatePriceAction` aplică efectul pe TOATE variantele
 * fiecărui produs selectat (§13.5: „aplicat variantelor produselor selectate").
 *
 * Fără îngustare de proprietate: produsele n-au `owner_user_id` (§7.4 — doar `R` pentru
 * Agent/Viewer, `CRUD` fix pentru Owner/Manager, fără ABAC). `scopeToOwnRecords()` nu se
 * atinge practic niciodată — `ProductPolicy::bulkWrite()` refuză Agentul înaintea oricărei
 * interogări (n-are `products.edit`) — dar interfața cere implementarea; rămâne un no-op.
 */
final class ProductBulkResource implements BulkWritableResource
{
    public function resourceType(): string
    {
        return 'products';
    }

    public function listClass(): string
    {
        return ProductList::class;
    }

    public function modelClass(): string
    {
        return Product::class;
    }

    public function newQuery(): Builder
    {
        return Product::query();
    }

    public function scopeToOwnRecords(Builder $query, User $user): void
    {
        // Intenționat gol — vezi docblock-ul clasei.
    }
}
