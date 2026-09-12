<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'location_id', 'carrier', 'service_level', 'tracking_number', 'label_url',
    'status', 'shipped_at', 'delivered_at', 'cost',
])]
class Shipment extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_LABEL_PENDING = 'label_pending';

    public const STATUS_LABEL_FAILED = 'label_failed';

    public const STATUS_LABEL_PURCHASED = 'label_purchased';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_EXCEPTION = 'exception';

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cost' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function shipmentLines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class);
    }
}
