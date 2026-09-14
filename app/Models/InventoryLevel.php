<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['variant_id', 'location_id', 'on_hand', 'reserved'])]
class InventoryLevel extends Model
{
    use BelongsToTenant, HasUlids;

    // Proiecție materializată — tabela are doar `updated_at`, fără `created_at`.
    public const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reserved' => 'integer',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * §10.5 — „disponibil de vânzare", niciodată negativ prin design (BR-STOCK-04): o
     * rezervare peste `on_hand` e respinsă mai devreme (confirmarea comenzii, alt pachet),
     * nu clampată aici. Loc unic — Pachetul A (`VariantResource`/Stock) și Pachetul
     * Comenzi (construirea unei comenzi, US-STOCK-02) citesc amândoi de aici, nu recalculează
     * fiecare pe cont propriu.
     */
    public function available(): int
    {
        return $this->on_hand - $this->reserved;
    }
}
