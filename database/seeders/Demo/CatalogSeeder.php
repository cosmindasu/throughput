<?php

namespace Database\Seeders\Demo;

use App\Models\Location;
use App\Models\Pipeline;
use App\Models\Product;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\Variant;
use Database\Factories\LocationFactory;
use Database\Factories\PipelineFactory;
use Database\Factories\ProductFactory;
use Database\Factories\StageFactory;
use Database\Factories\VariantFactory;

/**
 * Fundația de catalog per tenant: pipeline implicit + cele 6 etape canonice (specs.md
 * §9.2/§9.3), 2 locații de stoc, produse și variante pe verticala tenantului. Volum mic —
 * persistat prin Eloquent `create()` (nu bulk insert), în interiorul `TenantContext::run()`
 * al apelantului, deci `BelongsToTenant`/`HasUlids` funcționează normal.
 */
final class CatalogSeeder
{
    /** @var array<string, list<array{name: string, category: string, sizes: list<string>}>> */
    private const ARCHETYPES = [
        'fasteners' => [
            ['name' => 'Hex Bolt', 'category' => 'Bolts', 'sizes' => ['M6', 'M8', 'M10', 'M12', '1/4"', '3/8"', '1/2"']],
            ['name' => 'Carriage Bolt', 'category' => 'Bolts', 'sizes' => ['1/4"', '5/16"', '3/8"', '1/2"']],
            ['name' => 'Wing Nut', 'category' => 'Nuts', 'sizes' => ['M6', 'M8', '1/4"', '3/8"']],
            ['name' => 'Nylon Lock Nut', 'category' => 'Nuts', 'sizes' => ['M6', 'M8', 'M10', '1/4"', '3/8"']],
            ['name' => 'Flat Washer', 'category' => 'Washers', 'sizes' => ['M6', 'M8', 'M10', '1/4"', '3/8"']],
            ['name' => 'Lock Washer', 'category' => 'Washers', 'sizes' => ['M6', 'M8', 'M10', '1/4"']],
            ['name' => 'Socket Head Cap Screw', 'category' => 'Screws', 'sizes' => ['M4', 'M5', 'M6', 'M8']],
            ['name' => 'Wood Screw', 'category' => 'Screws', 'sizes' => ['1"', '1.5"', '2"', '3"']],
            ['name' => 'Machine Screw', 'category' => 'Screws', 'sizes' => ['M3', 'M4', 'M5']],
            ['name' => 'Anchor Bolt', 'category' => 'Anchors', 'sizes' => ['3/8"', '1/2"', '5/8"']],
            ['name' => 'Threaded Rod', 'category' => 'Rods', 'sizes' => ['12"', '24"', '36"']],
            ['name' => 'U-Bolt', 'category' => 'Specialty', 'sizes' => ['1/4"', '3/8"', '1/2"']],
        ],
        'hydraulics' => [
            ['name' => 'Hydraulic Hose', 'category' => 'Hose', 'sizes' => ['1/4"', '3/8"', '1/2"', '3/4"', '1"']],
            ['name' => 'Quick Coupler', 'category' => 'Fittings', 'sizes' => ['1/4"', '3/8"', '1/2"']],
            ['name' => 'Pressure Gauge', 'category' => 'Instrumentation', 'sizes' => ['0-1000 PSI', '0-3000 PSI', '0-5000 PSI']],
            ['name' => 'Hydraulic Cylinder', 'category' => 'Cylinders', 'sizes' => ['2" bore', '3" bore', '4" bore']],
            ['name' => 'Control Valve', 'category' => 'Valves', 'sizes' => ['2-way', '3-way', '4-way']],
            ['name' => 'Hydraulic Fitting', 'category' => 'Fittings', 'sizes' => ['1/4"', '3/8"', '1/2"', '3/4"']],
            ['name' => 'Filter Element', 'category' => 'Filtration', 'sizes' => ['10 micron', '25 micron', '50 micron']],
            ['name' => 'Pump Seal Kit', 'category' => 'Seals', 'sizes' => ['Standard', 'Heavy-Duty']],
            ['name' => 'Relief Valve', 'category' => 'Valves', 'sizes' => ['1/4"', '3/8"', '1/2"']],
            ['name' => 'Hose Clamp', 'category' => 'Fittings', 'sizes' => ['Small', 'Medium', 'Large']],
        ],
        'foodservice' => [
            ['name' => 'Stainless Prep Table', 'category' => 'Tables', 'sizes' => ['24"', '30"', '48"', '60"', '72"']],
            ['name' => 'Commercial Mixer', 'category' => 'Mixers', 'sizes' => ['5 qt', '20 qt', '30 qt', '60 qt']],
            ['name' => 'Chafing Dish', 'category' => 'Serving', 'sizes' => ['Half Size', 'Full Size']],
            ['name' => 'Food Storage Container', 'category' => 'Storage', 'sizes' => ['2 qt', '4 qt', '6 qt', '8 qt', '12 qt']],
            ['name' => 'Cutting Board', 'category' => 'Prep', 'sizes' => ['White', 'Red', 'Yellow', 'Green', 'Blue']],
            ['name' => 'Commercial Fryer', 'category' => 'Cooking', 'sizes' => ['40 lb', '50 lb']],
            ['name' => 'Ice Machine', 'category' => 'Refrigeration', 'sizes' => ['250 lb', '500 lb', '900 lb']],
            ['name' => 'Sheet Pan', 'category' => 'Bakeware', 'sizes' => ['Quarter', 'Half', 'Full']],
            ['name' => 'Stock Pot', 'category' => 'Cookware', 'sizes' => ['8 qt', '16 qt', '24 qt', '40 qt']],
            ['name' => 'Sauté Pan', 'category' => 'Cookware', 'sizes' => ['8"', '10"', '12"', '14"']],
        ],
    ];

    /** @var array<string, list<string>> */
    private const MODIFIERS = [
        'fasteners' => ['Zinc-Plated', 'Stainless Steel', 'Galvanized', 'Black Oxide', ''],
        'hydraulics' => ['Standard', 'Heavy-Duty', 'High-Pressure', ''],
        'foodservice' => ['NSF-Certified', 'Commercial-Grade', ''],
    ];

    /** @var array<string, array{0: float, 1: float}> */
    private const PRICE_RANGES = [
        'fasteners' => [0.12, 48.00],
        'hydraulics' => [6.50, 640.00],
        'foodservice' => [18.00, 2400.00],
    ];

    /**
     * @return array{
     *   pipeline_id: string,
     *   stages: list<array{id: string, name: string, position: int, is_won: bool, is_lost: bool, probability: int}>,
     *   locations: array{main: string, overflow: string},
     *   variants: list<array{id: string, product_id: string, name: string, sku: string, price: float, cost: float}>,
     * }
     */
    public function run(Tenant $tenant, array $config): array
    {
        $pipelineRow = (new PipelineFactory)->definition();
        $pipeline = Pipeline::create($pipelineRow);

        $stageDefs = [
            ['name' => 'New', 'position' => 1, 'is_won' => false, 'is_lost' => false, 'probability' => 10],
            ['name' => 'Qualified', 'position' => 2, 'is_won' => false, 'is_lost' => false, 'probability' => 30],
            ['name' => 'Proposal Sent', 'position' => 3, 'is_won' => false, 'is_lost' => false, 'probability' => 55],
            ['name' => 'Negotiation', 'position' => 4, 'is_won' => false, 'is_lost' => false, 'probability' => 75],
            ['name' => 'Won', 'position' => 5, 'is_won' => true, 'is_lost' => false, 'probability' => 100],
            ['name' => 'Lost', 'position' => 6, 'is_won' => false, 'is_lost' => true, 'probability' => 0],
        ];

        $stageFactory = new StageFactory;
        $stages = [];
        foreach ($stageDefs as $def) {
            $row = array_merge($stageFactory->definition(), $def, ['pipeline_id' => $pipeline->id]);
            $stage = Stage::create($row);
            $stages[] = ['id' => $stage->id] + $def;
        }

        $locationFactory = new LocationFactory;
        $main = Location::create($locationFactory->definition());
        $overflow = Location::create(array_merge($locationFactory->definition(), ['name' => 'Overflow Storage', 'is_default' => false]));

        $vertical = $config['vertical'];
        $archetypes = self::ARCHETYPES[$vertical];
        $modifiers = self::MODIFIERS[$vertical];
        [$priceMin, $priceMax] = self::PRICE_RANGES[$vertical];

        $productFactory = new ProductFactory;
        $variantFactory = new VariantFactory;

        $variants = [];
        $productIndex = 0;
        $archetypeCount = count($archetypes);
        $modifierCount = count($modifiers);

        while ($productIndex < $config['products']) {
            $archetype = $archetypes[$productIndex % $archetypeCount];
            $modifier = $modifiers[intdiv($productIndex, $archetypeCount) % $modifierCount];
            $name = trim("{$modifier} {$archetype['name']}");
            $productIndex++;

            $productRow = array_merge($productFactory->definition(), [
                'name' => $name,
                'category' => $archetype['category'],
                'unit_of_measure' => 'each',
                'is_active' => true,
            ]);
            $product = Product::create($productRow);

            $skuBase = strtoupper((string) preg_replace('/[^A-Za-z]+/', '', $archetype['name']));
            $basePrice = mt_rand((int) ($priceMin * 100), (int) ($priceMax * 100)) / 100;

            foreach ($archetype['sizes'] as $sizeIndex => $size) {
                $price = round($basePrice * (1 + $sizeIndex * 0.18), 2);
                $cost = round($price * (mt_rand(45, 75) / 100), 2);

                $variantRow = array_merge($variantFactory->definition(), [
                    'product_id' => $product->id,
                    'sku' => "{$config['code']}-{$skuBase}-{$productIndex}-".($sizeIndex + 1),
                    'attributes' => ['size' => $size],
                    'price' => $price,
                    'cost' => $cost,
                    'weight' => round(mt_rand(10, 4500) / 1000, 3),
                    'is_active' => true,
                ]);
                $variant = Variant::create($variantRow);

                $variants[] = [
                    'id' => $variant->id,
                    'product_id' => $product->id,
                    'name' => $name,
                    'sku' => $variantRow['sku'],
                    'price' => $price,
                    'cost' => $cost,
                ];
            }
        }

        return [
            'pipeline_id' => $pipeline->id,
            'stages' => $stages,
            'locations' => ['main' => $main->id, 'overflow' => $overflow->id],
            'variants' => $variants,
        ];
    }
}
