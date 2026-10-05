<?php

namespace Tests\Unit;

use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Order;
use App\Support\Activity\ActivityKind;

/**
 * Garda derivării: coloana `action` e un enum ÎNCHIS cu nouă valori, iar `updated` acoperă
 * deopotrivă o mutare de etapă, o factură plătită, o expediere și o editare oarecare. Pe
 * dashboard asta însemna zece rânduri identice „Updated Deal".
 *
 * Testul verifică exact ce nu se vede din enum: că `updated` se desparte corect după
 * `auditable_type` + cheile din `new_values`, și că NU se desparte când n-are de ce.
 * Fără bază de date — `ActivityKind::of()` citește doar atribute.
 */
function entry(string $action, ?string $type = null, array $new = []): ActivityLog
{
    $entry = new ActivityLog;
    $entry->action = $action;
    $entry->auditable_type = $type;
    $entry->new_values = $new;

    return $entry;
}

it('desparte `updated` dupa ce s-a schimbat de fapt', function (string $type, array $new, string $expected) {
    expect(ActivityKind::of(entry('updated', $type, $new)))->toBe($expected);
})->with([
    'mutare de etapa (cheie `stage_id`)' => [Deal::class, ['stage_id' => '01abc'], 'stage_moved'],
    'mutare de etapa (cheie `stage`)' => [Deal::class, ['stage' => 'Negotiation'], 'stage_moved'],
    'factura platita' => [Invoice::class, ['status' => Invoice::STATUS_PAID], 'invoice_paid'],
    // Forma pe care o scrie EXPEDIEREA REALĂ (`MarkShipmentShippedAction` mută comanda în
    // `fulfilled`/`partially_fulfilled` prin `save()`), nu o cheie `shipment` pe care o
    // producea doar seed-ul demo.
    'comanda expediata integral' => [Order::class, ['status' => 'fulfilled'], 'order_shipped'],
    'comanda expediata partial' => [Order::class, ['status' => 'partially_fulfilled'], 'order_shipped'],
    // Restul tranzițiilor de status ale unei comenzi NU sunt expedieri.
    'comanda confirmata' => [Order::class, ['status' => 'confirmed'], 'updated'],
    'comanda anulata' => [Order::class, ['status' => 'cancelled'], 'updated'],
    'membru dezactivat' => [Membership::class, ['status' => Membership::STATUS_DEACTIVATED], 'member_deactivated'],
]);

it('lasa `updated` neschimbat cand nu are de ce sa se desparta', function (string $label, ?string $type, array $new) {
    expect($label)->toBeString()
        ->and(ActivityKind::of(entry('updated', $type, $new)))->toBe('updated');
})->with([
    'editare oarecare pe o afacere' => ['titlu schimbat', Deal::class, ['title' => 'Nou']],
    'factura trecuta in alt status decat platit' => ['anulata', Invoice::class, ['status' => 'void']],
    'comanda fara expediere' => ['nota schimbata', Order::class, ['notes' => 'x']],
    'tip necunoscut' => ['fara tip', null, ['stage_id' => '01abc']],
]);

it('lasa verbele neambigue exact cum sunt', function (string $action) {
    expect(ActivityKind::of(entry($action, Deal::class, ['stage_id' => '01abc'])))->toBe($action);
})->with(['created', 'deleted', 'login', 'login_failed', 'exported', 'imported', 'bulk_action', 'role_changed']);
