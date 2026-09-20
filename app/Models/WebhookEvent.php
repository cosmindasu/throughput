<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

// Fără `BelongsToTenant`: tabela nu are `tenant_id` și nu are RLS — e o coadă de deduplicare
// cross-tenant, iar tenantul se rezolvă ulterior, în jobul de procesare (specs.md §19.1).
#[Fillable(['source', 'event_id', 'type', 'payload', 'payload_hash', 'status', 'received_at', 'processed_at', 'error_message'])]
class WebhookEvent extends Model
{
    use HasUlids;

    // Tabela nu are `created_at`/`updated_at` — doar `received_at`/`processed_at`.
    public $timestamps = false;

    // §12.3 — `source` e extensibil pentru evenimente de curierat, nu doar Stripe;
    // lotul de abonament (acest fișier) folosește exclusiv `SOURCE_STRIPE`.
    public const SOURCE_STRIPE = 'stripe';

    public const SOURCE_CARRIER = 'carrier';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    /**
     * §12.3 — eveniment cu semnătură VALIDĂ care nu ne privește: sandbox-ul Stripe e
     * împărțit cu alt proiect (decizia proprietarului, 2026-09-20), deci endpoint-ul nostru
     * primește și evenimentele aceluia. `failed` ar fi fost o acuzație greșită — nimic n-a
     * eșuat, doar că `data.object.customer` nu se mapează pe niciun tenant din ACEST
     * deployment. Distincția contează operațional: alerta din §25.2 urmărește `failed`, iar
     * un ecran de operare plin de roșu străin face roșul inutil.
     *
     * Stare TERMINALĂ, ca `processed`: niciun job nu e dispecerizat pentru un eveniment
     * `ignored`, deci nimic nu-l mai mută de aici.
     */
    public const STATUS_IGNORED = 'ignored';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
