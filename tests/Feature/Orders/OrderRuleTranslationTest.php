<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * ADR-022, plan-implementare.md „Lot I18N" Val 2, specs.md §15.8 FR-I18N-04 — mesajele de
 * REGULĂ DE BUSINESS aruncate din `App\Actions\Orders\**` (`ValidationException::withMessages`,
 * mutate în `lang/{en,fr}/rules.php`) respectă `users.locale`, la fel ca orice alt text
 * server-side.
 *
 * Verificat prin LANȚUL REAL de middleware (`SetLocale`, pe modelul `LocaleTest`), nu prin
 * `App::setLocale()` chemat direct în test — altfel testul ar acoperi doar catalogul, nu și
 * rezoluția (`users.locale` câștigă asupra cookie-ului/implicitului, FR-I18N-01).
 */
class OrderRuleTranslationTest extends TestCase
{
    public function test_a_french_speaking_user_sees_a_business_rule_refusal_in_french(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'owner@throughput.dev', Permissions::OWNER);
        $owner->forceFill(['locale' => 'fr'])->save();

        $order = $this->draftOrderWithoutLines($tenant, $owner);

        $this->clearDatabaseTenantContext();

        // Draft FĂRĂ linii — `App\Actions\Orders\ConfirmOrderAction` refuză cu
        // `rules.orders.lines_required`, înainte să ajungă la verificarea de stoc.
        $this->actingAs($owner)
            ->patch("/marlin/orders/{$order->getKey()}/confirm")
            ->assertSessionHasErrors([
                'lines' => 'Ajoutez au moins une ligne avant de confirmer cette commande.',
            ]);
    }

    public function test_the_same_refusal_stays_in_english_by_default(): void
    {
        // Regresie simetrică — un utilizator FĂRĂ preferință explicită (implicit `en`)
        // vede TOT engleza de dinainte de mutarea mesajelor în catalog; lotul n-are voie
        // să schimbe comportamentul implicit (plan-implementare.md, Val 5, livrabile).
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'owner@throughput.dev', Permissions::OWNER);

        $order = $this->draftOrderWithoutLines($tenant, $owner);

        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->patch("/marlin/orders/{$order->getKey()}/confirm")
            ->assertSessionHasErrors([
                'lines' => 'Add at least one line before confirming this order.',
            ]);
    }

    private function draftOrderWithoutLines(Tenant $tenant, User $owner): Order
    {
        return TenantContext::run($tenant, function () use ($owner): Order {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $owner->getKey();
            $account->save();

            $order = new Order([
                'account_id' => $account->getKey(),
                'owner_user_id' => $owner->getKey(),
                'status' => OrderStatus::Draft,
                'currency' => 'USD',
            ]);
            $order->created_by = $owner->getKey();
            $order->save();

            return $order;
        });
    }
}
