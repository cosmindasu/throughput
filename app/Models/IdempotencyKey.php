<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * §18.4 — cheia + hash-ul corpului cererii, păstrate 24 de ore împreună cu răspunsul
 * produs. Mecanica e în `App\Http\Middleware\RequireIdempotencyKey`; aici stă doar
 * forma rândului și calculul hash-ului, ca middleware-ul și testele să folosească
 * aceeași funcție, nu două implementări care pot diverge.
 */
#[Fillable(['key', 'request_hash', 'response_body', 'response_status', 'expires_at'])]
class IdempotencyKey extends Model
{
    use BelongsToTenant, HasUlids;

    /** §18.4 — „se stochează … pentru 24 de ore". */
    public const TTL_HOURS = 24;

    // Tabela nu are `created_at`/`updated_at` — doar `expires_at` (24h — §18.4).
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_status' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Amprenta corpului cererii.
     *
     * Payload-ul e SORTAT recursiv după cheie înainte de serializare: două cereri cu
     * aceleași câmpuri în altă ordine sunt aceeași cerere pentru orice client HTTP, deci
     * a le declara „payload diferit" (422) ar transforma un retry legitim într-o eroare.
     * Ordinea elementelor dintr-o LISTĂ rămâne semnificativă (liniile unei comenzi în altă
     * ordine sunt o altă comandă), deci se sortează doar cheile asociative.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function hashFor(array $payload): string
    {
        return hash('sha256', (string) json_encode(self::normalize($payload)));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function normalize(array $payload): array
    {
        $isList = array_is_list($payload);

        if (! $isList) {
            ksort($payload);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::normalize($value);
            }
        }

        return $payload;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Un rând revendicat de o cerere care încă nu a produs un răspuns. */
    public function isInFlight(): bool
    {
        return $this->response_status === null;
    }
}
