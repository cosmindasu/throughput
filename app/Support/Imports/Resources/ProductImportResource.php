<?php

namespace App\Support\Imports\Resources;

use App\Models\Product;
use App\Models\User;
use App\Support\Imports\ImportableResource;
use App\Support\Imports\ImportField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Produse „goale" — fără variantă (§14.1: „Products/Variants" acoperă DOUĂ resurse
 * distincte de import, per `resource_type` din migrația `imports`). Pentru un catalog cu
 * variante de vânzare (preț/SKU), vezi `VariantImportResource`, care creează produsul-părinte
 * din același rând când lipsește.
 *
 * Cheia de duplicat NU e dictată de FR-IMP-01 (fixează doar email/SKU) — decizie proprie,
 * motivată în raportul lotului: `name` normalizat (case-insensitive). `Product` n-are niciun
 * identificator extern propriu în schemă (SKU trăiește pe `variants`, nu pe `products"),
 * deci numele e cel mai apropiat analog disponibil — cu compromisul asumat că două produse
 * cu nume identic în categorii diferite s-ar respinge reciproc la reimport.
 */
final class ProductImportResource implements ImportableResource
{
    private const UNITS = ['each', 'box', 'pallet'];

    public function resourceType(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return __('imports.resources.products');
    }

    /** BR-I18N-01 — vezi docblock-ul identic din `AccountImportResource::fields()`. */
    public function fields(): array
    {
        return [
            new ImportField('name', 'imports.fields.products.name', true, ['required', 'string', 'max:255'], [
                'name', 'product name', 'item name', 'title',
                'nom du produit', "nom de l'article", 'désignation', 'nom',
            ]),
            new ImportField('category', 'imports.fields.products.category', false, ['nullable', 'string', 'max:255'], [
                'category', 'product category',
                'catégorie',
            ]),
            new ImportField('unit_of_measure', 'imports.fields.products.unit_of_measure', false, ['nullable', Rule::in(self::UNITS)], [
                'unit of measure', 'unit', 'uom',
                'unité de mesure', 'unité',
            ]),
        ];
    }

    public function duplicateSignature(array $mapped): ?array
    {
        $name = trim((string) ($mapped['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        return ['field' => 'name', 'value' => Str::lower($name)];
    }

    /**
     * `name_lower` — coloană GENERATĂ/STOCATĂ (migrația `2026_09_19_190000_...`), nu
     * `DB::raw('lower(name)')`: identic cu `AccountImportResource::existingValues()`, motivat
     * acolo pe larg — sub RLS, un index funcțional pe `lower(name)` e ignorat de planificator
     * (`lower()` nu e leakproof), o coloană stocată nu.
     */
    public function existingValues(string $field, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return Product::query()->whereIn('name_lower', $values)->pluck('name_lower')->all();
    }

    /** Fără părinte de rezolvat per rând (spre deosebire de Contacts/Variants) — no-op. */
    public function prepareChunk(array $mappedRows): void
    {
        // Intenționat gol.
    }

    public function writeRow(array $mapped, User $user): Model
    {
        $unit = trim((string) ($mapped['unit_of_measure'] ?? ''));

        return Product::create([
            'name' => trim((string) $mapped['name']),
            'category' => filled($mapped['category'] ?? null) ? trim((string) $mapped['category']) : null,
            'unit_of_measure' => in_array($unit, self::UNITS, true) ? $unit : 'each',
            'is_active' => true,
        ]);
    }
}
