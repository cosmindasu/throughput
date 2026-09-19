<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Matricea §7.4, rândul „Produse & variante" — CRUD pentru Owner/Manager, doar `R`
 * pentru Agent/Viewer. Spre deosebire de `AccountPolicy`, fără îngustare ABAC: un
 * produs n-are proprietar, deci Agentul editor/needitor depinde doar de rol.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can('products.view');
    }

    public function create(User $user): bool
    {
        return $user->can('products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('products.edit');
    }

    /**
     * Dreptul brut, nu starea — `Product::deletionBlockedReason()` (istoric de stoc/
     * comenzi pe variantele lui) e o stare a produsului, verificată separat de controller,
     * exact ca la `Account` (§7.5): altfel `can.delete` ar ascunde butonul fără explicație.
     */
    public function delete(User $user, Product $product): bool
    {
        return $user->can('products.delete');
    }

    /**
     * §13.5 (Pachetul C, valul „bulk", lotul E) — preț în masă și activare/dezactivare.
     * Legată de `products.edit`, ca `update()`: Agent/Viewer n-o au în catalog
     * (`Permissions::forRoles()`), deci nu ajung niciodată aici — fără îngustare ABAC de
     * adăugat, produsele n-au proprietar (docblock-ul clasei).
     */
    public function bulkWrite(User $user): bool
    {
        return $user->can('products.edit') && $user->can('bulk.write');
    }
}
