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
    'status', 'shipped_at', 'delivered_at', 'cost', 'error_message',
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

    /**
     * Faza 3, valul 2 — statusurile în care shipment-ul NU a părăsit fizic depozitul
     * încă: `shipment_lines` ale acestor shipment-uri sunt „deschise", deci se scad din
     * rămasul de expediat al liniei de comandă (`App\Support\Orders\RemainingToShip`),
     * ca două shipment-uri să nu poată revendica aceeași cantitate neexpediată.
     * `in_transit`/`delivered`/`exception` sunt EXCLUSE deliberat: odată marcat expediat
     * (`MarkShipmentShippedAction`), cantitatea a trecut deja în `quantity_fulfilled`,
     * altfel s-ar număra de două ori.
     *
     * @var list<string>
     */
    public const OPEN_STATUSES = [
        self::STATUS_LABEL_PENDING,
        self::STATUS_LABEL_FAILED,
        self::STATUS_LABEL_PURCHASED,
    ];

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
