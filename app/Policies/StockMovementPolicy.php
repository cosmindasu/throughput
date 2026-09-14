<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

/**
 * Matricea §7.4, rândul „Ajustări manuale de stoc": `stock.view` citește nivelele și
 * istoricul (Owner/Manager/Agent/Viewer — toți patru), `stock.adjust` scrie o mișcare
 * nouă (receptie, ajustare sau transfer — doar Owner/Manager; Agentul are „—" explicit
 * pe acest rând, deși vede `products`/`stock` în citire).
 *
 * `StockMovement` e append-only (ADR-004): nu există `update`/`delete` de autorizat aici
 * — `AppendOnly` (app/Concerns) le blochează la nivel de model, necondiționat de rol.
 */
class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function view(User $user, StockMovement $movement): bool
    {
        return $user->can('stock.view');
    }

    /** Recepție, ajustare sau transfer — toate trec prin `create` (o mișcare nouă). */
    public function create(User $user): bool
    {
        return $user->can('stock.adjust');
    }
}
