<?php

namespace App\Support\Imports\Resources;

use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Support\Imports\ImportableResource;
use App\Support\Imports\ImportField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Variante (§14.1, FR-IMP-01: cheia de duplicat e SKU, fixată de spec). Fixture-ul de
 * Faza 1 (§7.8, ~200 erori plantate: SKU duplicat / preț non-numeric / nume lipsă) e scris
 * pentru ACEASTĂ resursă, nu pentru `products`: coloanele lui (`SKU, Product Name, Category,
 * Unit of Measure, Price, Cost, Weight`) amestecă un câmp de variantă (SKU/Price/Cost/Weight,
 * care n-au ce căuta pe `products`) cu câmpuri de produs — semnalat explicit în raportul
 * lotului, cu numele fișierului („import-products-with-errors.csv") păstrat neschimbat.
 *
 * `product_name` NU e o coloană Variant — găsește/creează produsul-părinte pe nume
 * (case-insensitive), folosind `category`/`unit_of_measure` din ACELAȘI rând doar la
 * CREARE (un produs deja existent nu se suprascrie tăcut din rânduri ulterioare — BR-IMP-01,
 * „niciodată suprascriere silențioasă", aplicat prin analogie și aici).
 */
final class VariantImportResource implements ImportableResource
{
    private const UNITS = ['each', 'box', 'pallet'];

    /**
     * Hartă „nume de produs normalizat → id" — vezi docblock-ul identic din
     * `ContactImportResource::$accountIdByNormalizedName` (P2, review general — N+1 la
     * commit).
     *
     * @var array<string, string>
     */
    private array $productIdByNormalizedName = [];

    public function resourceType(): string
    {
        return 'variants';
    }

    public function label(): string
    {
        return 'Products/Variants';
    }

    /** BR-I18N-01 — vezi docblock-ul identic din `AccountImportResource::fields()`. */
    public function fields(): array
    {
        return [
            new ImportField('sku', 'SKU', true, ['required', 'string', 'max:255'], [
                'sku', 'product sku', 'item sku', 'variant sku',
                // „SKU" rămâne des netradus în franceza de comerț/logistică; „référence"/
                // „code article" sunt echivalentele uzuale — ambele adăugate.
                'référence', 'code article', 'référence sku',
            ]),
            new ImportField('product_name', 'Product name', true, ['required', 'string', 'max:255'], [
                'product name', 'product', 'item name',
                'nom du produit', 'produit',
            ]),
            new ImportField('category', 'Category', false, ['nullable', 'string', 'max:255'], [
                'category', 'product category',
                'catégorie',
            ]),
            new ImportField('unit_of_measure', 'Unit of measure', false, ['nullable', Rule::in(self::UNITS)], [
                'unit of measure', 'unit', 'uom',
                'unité de mesure', 'unité',
            ]),
            new ImportField('price', 'Price', true, ['required', 'numeric', 'min:0'], [
                'price', 'unit price', 'sale price',
                'prix', 'prix unitaire', 'prix de vente',
            ]),
            new ImportField('cost', 'Cost', true, ['required', 'numeric', 'min:0'], [
                'cost', 'unit cost',
                'coût', 'coût unitaire',
            ]),
            new ImportField('weight', 'Weight', false, ['nullable', 'numeric', 'min:0'], [
                'weight', 'item weight',
                'poids',
            ]),
        ];
    }

    public function duplicateSignature(array $mapped): ?array
    {
        $sku = trim((string) ($mapped['sku'] ?? ''));

        if ($sku === '') {
            return null;
        }

        // SKU-urile sunt case-sensitive, ca și constrângerea unică din schemă
        // (`unique(['tenant_id', 'sku'])`, comparație simplă de șir) — fără `Str::lower()`.
        return ['field' => 'sku', 'value' => $sku];
    }

    public function existingValues(string $field, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return Variant::query()->whereIn('sku', $values)->pluck('sku')->all();
    }

    /**
     * O SINGURĂ interogare `whereIn('name_lower', ...)` pentru TOATE numele de produs
     * distincte din chunk — nu una per rând (P2, review general). `name_lower` (coloană
     * GENERATĂ/STOCATĂ, migrația `2026_09_19_190000_...`).
     *
     * @param  list<array<string, mixed>>  $mappedRows
     */
    public function prepareChunk(array $mappedRows): void
    {
        $this->productIdByNormalizedName = [];

        $names = collect($mappedRows)
            ->map(fn (array $row) => trim((string) ($row['product_name'] ?? '')))
            ->filter(fn (string $name) => $name !== '')
            ->map(fn (string $name) => Str::lower($name))
            ->unique()
            ->values()
            ->all();

        if ($names === []) {
            return;
        }

        Product::query()
            ->whereIn('name_lower', $names)
            ->get(['id', 'name_lower'])
            ->each(function (Product $product): void {
                $this->productIdByNormalizedName[$product->name_lower] = $product->getKey();
            });
    }

    public function writeRow(array $mapped, User $user): Model
    {
        $productId = $this->findOrCreateProductId($mapped);
        $unit = trim((string) ($mapped['unit_of_measure'] ?? ''));

        return Variant::create([
            'product_id' => $productId,
            'sku' => trim((string) $mapped['sku']),
            'price' => (float) $mapped['price'],
            'cost' => (float) $mapped['cost'],
            'weight' => filled($mapped['weight'] ?? null) ? (float) $mapped['weight'] : null,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function findOrCreateProductId(array $mapped): string
    {
        $productName = trim((string) $mapped['product_name']);
        $normalized = Str::lower($productName);

        if (isset($this->productIdByNormalizedName[$normalized])) {
            return $this->productIdByNormalizedName[$normalized];
        }

        $unit = trim((string) ($mapped['unit_of_measure'] ?? ''));

        $product = Product::create([
            'name' => $productName,
            'category' => filled($mapped['category'] ?? null) ? trim((string) $mapped['category']) : null,
            'unit_of_measure' => in_array($unit, self::UNITS, true) ? $unit : 'each',
            'is_active' => true,
        ]);

        // Write-through — vezi docblock-ul proprietății de mai sus.
        $this->productIdByNormalizedName[$normalized] = $product->getKey();

        return $product->getKey();
    }
}
