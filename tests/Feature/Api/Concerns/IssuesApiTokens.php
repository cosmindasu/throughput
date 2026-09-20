<?php

namespace Tests\Feature\Api\Concerns;

use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Fixtură comună a testelor de API (specs.md §18) — fișier NOU, doar pentru acest lot,
 * la fel ca `Tests\Concerns\CreatesInvoices`: nu atinge `Tests\TestCase`.
 *
 * Toate ajutoarele deschid singure contextul de tenant, ca apelantul să scrie un test
 * despre HTTP, nu despre tenancy.
 */
trait IssuesApiTokens
{
    /**
     * @param  list<string>  $abilities
     */
    protected function issueToken(Tenant $tenant, User $user, array $abilities, ?Carbon $expiresAt = null): string
    {
        return TenantContext::run($tenant, function () use ($user, $abilities, $expiresAt): string {
            [, $plainTextToken] = ApiToken::issue($user, 'Test token', $abilities, $expiresAt);

            return $plainTextToken;
        });
    }

    /**
     * @param  list<string>  $abilities
     * @return array{0: ApiToken, 1: string}
     */
    protected function issueTokenRow(Tenant $tenant, User $user, array $abilities): array
    {
        return TenantContext::run($tenant, fn (): array => ApiToken::issue($user, 'Test token', $abilities));
    }

    /**
     * @return array<string, string>
     */
    protected function bearer(string $plainTextToken): array
    {
        return ['Authorization' => 'Bearer '.$plainTextToken, 'Accept' => 'application/json'];
    }

    protected function makeAccount(Tenant $tenant, User $owner, string $name = 'Northwind Industrial Supply LLC'): Account
    {
        return TenantContext::run($tenant, function () use ($owner, $name): Account {
            $account = new Account(['name' => $name, 'status' => Account::STATUS_ACTIVE, 'credit_terms' => 'net_30']);
            $account->created_by = $owner->getKey();
            $account->save();

            return $account;
        });
    }

    /**
     * Variantă + locație implicită + nivel de stoc, minimul de care au nevoie
     * `POST /orders` (linii) și `POST /stock-movements`.
     *
     * @return array{0: Variant, 1: Location}
     */
    protected function makeStockFixture(Tenant $tenant, int $onHand = 50): array
    {
        return TenantContext::run($tenant, function () use ($onHand): array {
            $product = new Product(['name' => 'Hex bolt M8', 'is_active' => true]);
            $product->save();

            $variant = new Variant([
                'product_id' => $product->getKey(),
                'sku' => 'HEX-M8-'.random_int(1000, 9999),
                'price' => 12.50,
                'cost' => 6.00,
                'is_active' => true,
            ]);
            $variant->save();

            $location = new Location(['name' => 'Main warehouse', 'is_default' => true]);
            $location->save();

            $variant->inventoryLevels()->create([
                'location_id' => $location->getKey(),
                'on_hand' => $onHand,
                'reserved' => 0,
            ]);

            return [$variant, $location];
        });
    }

    protected function makeConfirmedOrder(Tenant $tenant, Account $account, User $owner, float $total = 500.0): Order
    {
        return TenantContext::run($tenant, function () use ($account, $owner, $total): Order {
            $order = new Order([
                'account_id' => $account->getKey(),
                'owner_user_id' => $owner->getKey(),
                'order_number' => 'ORD-'.random_int(100000, 999999),
                'status' => OrderStatus::Confirmed,
                'currency' => 'USD',
                'subtotal' => $total,
                'discount_total' => 0,
                'shipping_total' => 0,
                'grand_total' => $total,
                'placed_at' => now(),
            ]);
            $order->created_by = $owner->getKey();
            $order->save();

            return $order;
        });
    }
}
