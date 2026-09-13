<?php

namespace Database\Seeders\Demo;

use App\Models\InventoryLevel;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\StockMovement;
use App\Models\Tenant;
use Database\Factories\InventoryLevelFactory;
use Database\Factories\OrderFactory;
use Database\Factories\OrderLineFactory;
use Database\Factories\ShipmentFactory;
use Database\Factories\ShipmentLineFactory;
use Database\Seeders\Support\ActivityLogRecorder;
use Database\Seeders\Support\ChunkedWriter;
use Database\Seeders\Support\DemoClock;
use Database\Seeders\Support\DemoId;
use Database\Seeders\Support\Rand;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Cea mai mare parte a volumului de demo (specs.md §21.1): comenzi + linii + shipment-uri
 * + `stock_movements`. Ledger-ul de stoc (ADR-004) e ținut ca o hartă on_hand în PHP pe
 * durata generării — cantitatea onorată pe fiecare linie e clampată la disponibilul
 * acumulat înainte de a fi scrisă, ca `inventory_levels` finale (scrise o singură dată, la
 * coadă) să reconcilieze exact cu suma `stock_movements.delta` (BR-STOCK-02), fără să mai
 * fie nevoie de o trecere separată de reconciliere.
 *
 * Simplificare asumată (raportată în livrabil): toate comenzile se onorează din locația
 * principală ("Main Warehouse"); "Overflow Storage" există doar ca destinație de transfer,
 * pentru realismul mișcărilor de tip `transfer`.
 */
final class StockAndOrdersSeeder
{
    /**
     * @param  array{pipeline_id: string, stages: list<array>, locations: array{main: string, overflow: string}, variants: list<array>}  $catalog
     * @param  array{accounts: list<array>, contacts_by_account: array<string, list<string>>}  $accountsResult
     * @param  array{won_deal_ids_by_account: array<string, list<string>>}  $dealsResult
     * @param  array{owner_id: string, demo_agent_id: ?string, pool: list<array{id: string, role: string}>}  $staff
     * @return list<array{id: string, account_id: string, status: string, grand_total: float, currency: string, created_at: int, placed_at: ?int, owner_user_id: string}> timestamp-uri Unix, UTC
     */
    public function run(Tenant $tenant, array $config, array $catalog, array $accountsResult, array $dealsResult, array $staff, ?Command $command, ActivityLogRecorder $activityLog): array
    {
        $variants = $catalog['variants'];
        $mainLocation = $catalog['locations']['main'];
        $overflowLocation = $catalog['locations']['overflow'];

        $movementWriter = new ChunkedWriter(StockMovement::class, 1000, $command, 'Stock movements', max(1, count($variants)) * 8);

        $manager = current(array_filter($staff['pool'], fn ($m) => $m['role'] === 'Manager')) ?: null;
        $warehouseActor = $manager['id'] ?? $staff['owner_id'];

        $stockPool = $this->seedBaselineStock($tenant, $variants, $mainLocation, $overflowLocation, $warehouseActor, $movementWriter);

        $accounts = $accountsResult['accounts'];

        if ($accounts === [] || $variants === []) {
            $movementWriter->flush();

            return [];
        }

        $orderTotal = $config['orders'];
        $orderWriter = new ChunkedWriter(Order::class, 1000, $command, 'Orders', $orderTotal);
        $lineWriter = (new ChunkedWriter(OrderLine::class, 1000, $command, 'Order lines', (int) ($orderTotal * 2.2)))
            ->dependsOn($orderWriter);   // FK order_lines.order_id
        $shipmentWriter = (new ChunkedWriter(Shipment::class, 1000, $command, 'Shipments', (int) ($orderTotal * 0.7)))
            ->dependsOn($orderWriter);   // FK shipments.order_id
        $shipmentLineWriter = (new ChunkedWriter(ShipmentLine::class, 1000, $command, 'Shipment lines', (int) ($orderTotal * 1.3)))
            ->dependsOn($shipmentWriter, $lineWriter);   // FK shipment_id + order_line_id

        $orderFactory = new OrderFactory;
        $lineFactory = new OrderLineFactory;
        $shipmentFactory = new ShipmentFactory;
        $shipmentLineFactory = new ShipmentLineFactory;

        $ownerIds = array_column($staff['pool'], 'id');
        $topCount = max(1, intdiv(count($accounts), 10));
        $accountCount = count($accounts);

        $orderNumber = 10000;
        $reservedByVariant = [];
        $summaries = [];

        for ($i = 1; $i <= $orderTotal; $i++) {
            $account = Rand::bool(30)
                ? $accounts[random_int(0, $topCount - 1)]
                : $accounts[random_int(0, $accountCount - 1)];

            $createdAt = DemoClock::historicalDate(24);
            if ($createdAt->getTimestamp() < $account['created_at']) {
                $createdAt = Carbon::createFromTimestamp($account['created_at'], 'UTC')->addDays(random_int(0, 5));
            }

            $bucket = Rand::weightedKey([
                'draft' => 5,
                'confirmed_clean' => 12,
                'confirmed_pending' => 3,
                'partially_fulfilled' => 12,
                'fulfilled' => 58,
                'cancelled' => 10,
            ]);

            $status = match ($bucket) {
                'draft' => Order::STATUS_DRAFT,
                'confirmed_clean', 'confirmed_pending' => Order::STATUS_CONFIRMED,
                'partially_fulfilled' => Order::STATUS_PARTIALLY_FULFILLED,
                'fulfilled' => Order::STATUS_FULFILLED,
                'cancelled' => Order::STATUS_CANCELLED,
            };

            $reservesStock = in_array($status, [Order::STATUS_CONFIRMED, Order::STATUS_PARTIALLY_FULFILLED], true);

            $ownerId = Rand::bool(80) ? $account['owner_user_id'] : $ownerIds[array_rand($ownerIds)];
            $orderId = DemoId::next();

            $lineCount = (int) Rand::weightedKey([1 => 30, 2 => 35, 3 => 20, 4 => 15]);
            $lineVariants = Rand::distinct($variants, $lineCount);
            $lastIndex = count($lineVariants) - 1;

            $lines = [];
            $subtotal = 0.0;
            $discountTotal = 0.0;

            foreach ($lineVariants as $index => $variant) {
                $bucketQty = Rand::weightedKey(['small' => 55, 'medium' => 35, 'large' => 10]);
                $quantity = match ($bucketQty) {
                    'small' => random_int(1, 5),
                    'medium' => random_int(6, 20),
                    default => random_int(21, 60),
                };

                $unitPrice = Rand::bool(20)
                    ? round($variant['price'] * (1 - random_int(3, 8) / 100), 2)
                    : (float) $variant['price'];

                $lineSubtotal = round($quantity * $unitPrice, 2);
                $discount = Rand::bool(15) ? round($lineSubtotal * (random_int(5, 15) / 100), 2) : 0.0;
                $lineTotal = round($lineSubtotal - $discount, 2);

                $intendedFulfilled = match ($status) {
                    Order::STATUS_FULFILLED => $quantity,
                    Order::STATUS_PARTIALLY_FULFILLED => $index === $lastIndex ? intdiv($quantity, 2) : $quantity,
                    default => 0,
                };

                $actualFulfilled = 0;
                if ($intendedFulfilled > 0) {
                    $available = $stockPool[$variant['id']][$mainLocation] ?? 0;
                    $actualFulfilled = min($intendedFulfilled, max(0, $available));

                    if ($actualFulfilled > 0) {
                        $stockPool[$variant['id']][$mainLocation] = $available - $actualFulfilled;
                    }
                }

                $lines[] = [
                    'id' => DemoId::next(),
                    'variant_id' => $variant['id'],
                    'description' => "{$variant['name']} — {$variant['sku']}",
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'line_total' => $lineTotal,
                    'quantity_fulfilled' => $actualFulfilled,
                ];

                if ($reservesStock) {
                    $reservedByVariant[$variant['id']] = ($reservedByVariant[$variant['id']] ?? 0) + ($quantity - $actualFulfilled);
                }

                $subtotal += $lineSubtotal;
                $discountTotal += $discount;
            }

            $shippingTotal = in_array($status, [Order::STATUS_DRAFT, Order::STATUS_CANCELLED], true)
                ? 0.0
                : Rand::money(6.5, 45.0);

            $subtotal = round($subtotal, 2);
            $discountTotal = round($discountTotal, 2);
            $grandTotal = round($subtotal - $discountTotal + $shippingTotal, 2);

            $placedAt = $status === Order::STATUS_DRAFT ? null : $createdAt->copy()->addMinutes(random_int(5, 360));
            $orderNumberValue = $status === Order::STATUS_DRAFT ? null : "{$config['code']}-".($orderNumber++);

            $dealId = null;
            $wonDeals = $dealsResult['won_deal_ids_by_account'][$account['id']] ?? [];
            if ($wonDeals !== [] && Rand::bool(15)) {
                $dealId = $wonDeals[array_rand($wonDeals)];
            }

            $contactId = $accountsResult['contacts_by_account'][$account['id']][0] ?? null;

            $orderRow = $orderFactory->definition();
            $orderRow['id'] = $orderId;
            $orderRow['tenant_id'] = $tenant->id;
            $orderRow['order_number'] = $orderNumberValue;
            $orderRow['account_id'] = $account['id'];
            $orderRow['contact_id'] = $contactId;
            $orderRow['deal_id'] = $dealId;
            $orderRow['owner_user_id'] = $ownerId;
            $orderRow['status'] = $status;
            $orderRow['subtotal'] = $subtotal;
            $orderRow['discount_total'] = $discountTotal;
            $orderRow['shipping_total'] = $shippingTotal;
            $orderRow['grand_total'] = $grandTotal;
            $orderRow['placed_at'] = $placedAt;
            $orderRow['created_by'] = $ownerId;
            $orderRow['created_at'] = $createdAt;
            $orderRow['updated_at'] = $placedAt ?? $createdAt;

            $orderWriter->push($orderRow);
            $activityLog->record($tenant->id, $ownerId, 'created', Order::class, $orderId, $createdAt);

            if ($status !== Order::STATUS_DRAFT) {
                $activityLog->record($tenant->id, $ownerId, 'updated', Order::class, $orderId, $placedAt, ['status' => 'draft'], ['status' => $status]);
            }

            $shipmentLines = [];

            foreach ($lines as $line) {
                $lineRow = $lineFactory->definition();
                $lineRow['id'] = $line['id'];
                $lineRow['tenant_id'] = $tenant->id;
                $lineRow['order_id'] = $orderId;
                $lineRow['variant_id'] = $line['variant_id'];
                $lineRow['description'] = $line['description'];
                $lineRow['quantity'] = $line['quantity'];
                $lineRow['unit_price'] = $line['unit_price'];
                $lineRow['discount'] = $line['discount'];
                $lineRow['line_total'] = $line['line_total'];
                $lineRow['quantity_fulfilled'] = $line['quantity_fulfilled'];
                $lineRow['created_at'] = $createdAt;
                $lineRow['updated_at'] = $placedAt ?? $createdAt;

                $lineWriter->push($lineRow);

                if ($line['quantity_fulfilled'] > 0) {
                    $shipmentLines[] = $line;
                }
            }

            if ($shipmentLines !== [] && in_array($bucket, ['partially_fulfilled', 'fulfilled'], true)) {
                $shipmentId = DemoId::next();
                $shippedAt = DemoClock::shortlyAfter($placedAt, 12, 96);

                $shipmentStatus = $bucket === 'fulfilled'
                    ? (Rand::bool(90) ? Shipment::STATUS_DELIVERED : Shipment::STATUS_IN_TRANSIT)
                    : Rand::weightedKey([Shipment::STATUS_IN_TRANSIT => 55, Shipment::STATUS_DELIVERED => 40, Shipment::STATUS_EXCEPTION => 5]);

                $shipmentRow = $shipmentFactory->definition();
                $shipmentRow['id'] = $shipmentId;
                $shipmentRow['tenant_id'] = $tenant->id;
                $shipmentRow['order_id'] = $orderId;
                $shipmentRow['location_id'] = $mainLocation;
                $shipmentRow['carrier'] = $config['carrier'];
                $shipmentRow['status'] = $shipmentStatus;
                $shipmentRow['tracking_number'] = strtoupper((string) Str::random(2)).random_int(100000000, 999999999);
                $shipmentRow['label_url'] = "https://storage.demo.throughput.dev/labels/{$shipmentId}.pdf";
                $shipmentRow['shipped_at'] = $shippedAt;
                $shipmentRow['delivered_at'] = $shipmentStatus === Shipment::STATUS_DELIVERED ? DemoClock::shortlyAfter($shippedAt, 24, 120) : null;
                $shipmentRow['cost'] = Rand::money(5.5, 95.0);
                $shipmentRow['created_at'] = $placedAt;
                $shipmentRow['updated_at'] = $shipmentRow['delivered_at'] ?? $shippedAt;

                $shipmentWriter->push($shipmentRow);
                $activityLog->record($tenant->id, $ownerId, 'updated', Order::class, $orderId, $shippedAt, null, ['shipment' => 'shipped']);

                foreach ($shipmentLines as $line) {
                    $shipmentLineRow = $shipmentLineFactory->definition();
                    $shipmentLineRow['id'] = DemoId::next();
                    $shipmentLineRow['tenant_id'] = $tenant->id;
                    $shipmentLineRow['shipment_id'] = $shipmentId;
                    $shipmentLineRow['order_line_id'] = $line['id'];
                    $shipmentLineRow['quantity'] = $line['quantity_fulfilled'];
                    $shipmentLineWriter->push($shipmentLineRow);

                    $movementWriter->push([
                        'id' => DemoId::next(),
                        'tenant_id' => $tenant->id,
                        'variant_id' => $line['variant_id'],
                        'location_id' => $mainLocation,
                        'delta' => -$line['quantity_fulfilled'],
                        'reason' => 'sale',
                        'ref_type' => 'shipment',
                        'ref_id' => $shipmentId,
                        'note' => null,
                        'created_by' => $ownerId,
                        'created_at' => $shippedAt,
                    ]);
                }
            } elseif ($bucket === 'confirmed_pending') {
                // Etichetă în coadă/eșuată (ADR-013) — nimic nu a părăsit fizic depozitul,
                // deci fără shipment_lines/stock_movements, doar shipment-ul "blocat".
                $shipmentId = DemoId::next();
                $shipmentStatus = Rand::bool(60) ? Shipment::STATUS_LABEL_PENDING : Shipment::STATUS_LABEL_FAILED;

                $shipmentRow = $shipmentFactory->definition();
                $shipmentRow['id'] = $shipmentId;
                $shipmentRow['tenant_id'] = $tenant->id;
                $shipmentRow['order_id'] = $orderId;
                $shipmentRow['location_id'] = $mainLocation;
                $shipmentRow['carrier'] = $config['carrier'];
                $shipmentRow['status'] = $shipmentStatus;
                $shipmentRow['tracking_number'] = null;
                $shipmentRow['label_url'] = null;
                $shipmentRow['shipped_at'] = null;
                $shipmentRow['delivered_at'] = null;
                $shipmentRow['cost'] = null;
                $shipmentRow['created_at'] = $placedAt;
                $shipmentRow['updated_at'] = $placedAt;

                $shipmentWriter->push($shipmentRow);
            }

            // Timestamp-uri întregi, nu obiecte Carbon: lista ține TOATE comenzile tenantului
            // până la facturare. Măsurat pe 30.000 de rânduri (Marlin): 134,6 MB cu două
            // Carbon pe rând, 16,1 MB cu întregi. Diferența decide dacă `demo:reset` încape în
            // `memory_limit` 128M din containerul `scheduler` sau moare după ce `migrate:fresh`
            // a șters deja schema — cu demo-ul public gol până a doua zi.
            $summaries[] = [
                'id' => $orderId,
                'account_id' => $account['id'],
                'status' => $status,
                'grand_total' => $grandTotal,
                'currency' => $orderRow['currency'],
                'created_at' => $createdAt->getTimestamp(),
                'placed_at' => $placedAt?->getTimestamp(),
                'owner_user_id' => $ownerId,
            ];
        }

        $orderWriter->flush();
        $lineWriter->flush();
        $shipmentWriter->flush();
        $shipmentLineWriter->flush();
        $movementWriter->flush();
        $activityLog->flush();

        $this->finalizeInventoryLevels($tenant, $variants, $mainLocation, $stockPool, $reservedByVariant, $command);

        return $summaries;
    }

    /**
     * Recepții inițiale + reaprovizionări pe 24 de luni, per variantă (ADR-004): stabilesc
     * disponibilul din care comenzile "consumă" mai jos. ~5% dintre variante primesc și un
     * transfer către Overflow Storage (BR-STOCK-03), ~3% o ajustare cu notă obligatorie.
     *
     * @param  list<array{id: string, cost: float}>  $variants
     * @return array<string, array<string, int>> variant_id => [location_id => on_hand]
     */
    private function seedBaselineStock(Tenant $tenant, array $variants, string $mainLocation, string $overflowLocation, string $actorId, ChunkedWriter $writer): array
    {
        $pool = [];

        foreach ($variants as $variant) {
            $variantId = $variant['id'];
            $pool[$variantId][$mainLocation] = 0;

            $unitCost = max(0.05, (float) $variant['cost']);
            $baseQty = $unitCost < 5 ? random_int(600, 4000) : ($unitCost < 50 ? random_int(150, 900) : random_int(20, 180));

            $cursor = Carbon::now()->subMonths(24)->addDays(random_int(0, 20));
            $replenishments = random_int(3, 6);

            for ($r = 0; $r <= $replenishments; $r++) {
                if ($cursor->greaterThan(Carbon::now())) {
                    break;
                }

                $qty = $r === 0 ? $baseQty : (int) round($baseQty * (random_int(30, 70) / 100));
                $pool[$variantId][$mainLocation] += $qty;

                $writer->push([
                    'id' => DemoId::next(),
                    'tenant_id' => $tenant->id,
                    'variant_id' => $variantId,
                    'location_id' => $mainLocation,
                    'delta' => $qty,
                    'reason' => 'receipt',
                    'ref_type' => null,
                    'ref_id' => null,
                    'note' => null,
                    'created_by' => $actorId,
                    'created_at' => $cursor->copy(),
                ]);

                $cursor = $cursor->copy()->addDays(random_int(35, 95));
            }

            if (Rand::bool(5) && $pool[$variantId][$mainLocation] > 20) {
                $transferQty = (int) max(5, round($pool[$variantId][$mainLocation] * (random_int(10, 15) / 100)));
                $transferAt = Carbon::now()->subMonths(random_int(1, 11));
                $refId = DemoId::next();

                $pool[$variantId][$mainLocation] -= $transferQty;
                $pool[$variantId][$overflowLocation] = ($pool[$variantId][$overflowLocation] ?? 0) + $transferQty;

                $writer->push(['id' => DemoId::next(), 'tenant_id' => $tenant->id, 'variant_id' => $variantId, 'location_id' => $mainLocation, 'delta' => -$transferQty, 'reason' => 'transfer', 'ref_type' => 'transfer', 'ref_id' => $refId, 'note' => null, 'created_by' => $actorId, 'created_at' => $transferAt]);
                $writer->push(['id' => DemoId::next(), 'tenant_id' => $tenant->id, 'variant_id' => $variantId, 'location_id' => $overflowLocation, 'delta' => $transferQty, 'reason' => 'transfer', 'ref_type' => 'transfer', 'ref_id' => $refId, 'note' => null, 'created_by' => $actorId, 'created_at' => $transferAt]);
            }

            if (Rand::bool(3)) {
                $onHand = $pool[$variantId][$mainLocation];
                $swing = max(1, intdiv($onHand, 10));
                $delta = Rand::bool(50) ? random_int(1, $swing) : -random_int(1, $swing);
                $delta = max($delta, -$onHand);
                $pool[$variantId][$mainLocation] += $delta;

                $writer->push([
                    'id' => DemoId::next(),
                    'tenant_id' => $tenant->id,
                    'variant_id' => $variantId,
                    'location_id' => $mainLocation,
                    'delta' => $delta,
                    'reason' => 'adjustment',
                    'ref_type' => null,
                    'ref_id' => null,
                    'note' => 'Cycle count correction after physical inventory audit.',
                    'created_by' => $actorId,
                    'created_at' => Carbon::now()->subMonths(random_int(0, 6))->subDays(random_int(0, 27)),
                ]);
            }
        }

        return $pool;
    }

    /**
     * `inventory_levels` finale (proiecție materializată, ADR-004): `on_hand` = suma
     * ledger-ului de mai sus, `reserved` = cererea neonorată de pe comenzile confirmed/
     * partially_fulfilled — clampat la `on_hand` ca "available" să nu apară negativ pe
     * datele semănate (BR-STOCK-04).
     *
     * @param  list<array{id: string}>  $variants
     * @param  array<string, array<string, int>>  $stockPool
     * @param  array<string, int>  $reservedByVariant
     */
    private function finalizeInventoryLevels(Tenant $tenant, array $variants, string $mainLocation, array $stockPool, array $reservedByVariant, ?Command $command): void
    {
        $total = 0;
        foreach ($stockPool as $locations) {
            $total += count($locations);
        }

        $writer = new ChunkedWriter(InventoryLevel::class, 1000, $command, 'Inventory levels', max(1, $total));
        $factory = new InventoryLevelFactory;
        $now = Carbon::now();

        foreach ($variants as $variant) {
            $variantId = $variant['id'];

            foreach ($stockPool[$variantId] ?? [] as $locationId => $onHand) {
                $onHand = max(0, $onHand);
                $reserved = $locationId === $mainLocation ? min($reservedByVariant[$variantId] ?? 0, $onHand) : 0;

                $row = $factory->definition();
                $row['id'] = DemoId::next();
                $row['tenant_id'] = $tenant->id;
                $row['variant_id'] = $variantId;
                $row['location_id'] = $locationId;
                $row['on_hand'] = $onHand;
                $row['reserved'] = $reserved;
                $row['updated_at'] = $now;

                $writer->push($row);
            }
        }

        $writer->flush();
    }
}
