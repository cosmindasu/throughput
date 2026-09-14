<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Variant;

/**
 * Variantele nu au permisiuni proprii în catalog (§7.4 le tratează pe același rând cu
 * produsele) — aceleași `products.*`, verificate aici pentru modelul `Variant`.
 */
class VariantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.view');
    }

    public function view(User $user, Variant $variant): bool
    {
        return $user->can('products.view');
    }

    public function create(User $user): bool
    {
        return $user->can('products.create');
    }

    public function update(User $user, Variant $variant): bool
    {
        return $user->can('products.edit');
    }

    /** Dreptul brut — `Variant::deletionBlockedReason()` e verificat separat (§7.5). */
    public function delete(User $user, Variant $variant): bool
    {
        return $user->can('products.delete');
    }
}
