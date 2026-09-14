<?php

namespace App\Actions\Stock\Concerns;

use App\Models\InventoryLevel;
use App\Models\Scopes\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Convenția de blocare pe stoc, comună cu lotul Comenzi (task brief): orice cod care
 * blochează rânduri `inventory_levels` o face cu `lockForUpdate()`, într-o singură
 * interogare ordonată `ORDER BY variant_id, location_id`, înainte de orice scriere —
 * ca lotul acesta (scrie `on_hand`) și lotul Comenzi (scrie `reserved`) să nu se poată
 * bloca reciproc (deadlock) când ating aceleași rânduri în ordine diferită.
 *
 * `RecordStockMovementAction` (un rând) și `TransferStockAction` (două rânduri, aceeași
 * variantă, locații diferite) trec prin `lockLevels()` — o singură variantă, mai multe
 * locații. `ConfirmOrderAction`/`CancelOrderAction` (code review P1-003) au nevoie de
 * forma oglindă — mai multe variante, o singură locație — și trec prin
 * `lockLevelsAtLocation()`. Amândouă sunt fațete subțiri peste `lockLevelPairs()`, care
 * lucrează pe mulțimea generală de perechi (variantă, locație): o singură interogare de
 * lock, care blochează EXACT perechile cerute (nu produsul cartezian al variantelor ×
 * locațiilor implicate), plus crearea la cerere a rândurilor lipsă, înainte de blocare.
 */
trait LocksInventoryLevels
{
    /**
     * @param  list<string>  $locationIds
     * @return Collection<string, InventoryLevel> cheie = location_id
     */
    private function lockLevels(string $variantId, array $locationIds): Collection
    {
        $pairs = array_map(
            static fn (string $locationId): array => ['variant_id' => $variantId, 'location_id' => $locationId],
            $locationIds,
        );

        return $this->lockLevelPairs($pairs)
            ->mapWithKeys(static fn (InventoryLevel $level): array => [$level->location_id => $level]);
    }

    /**
     * @param  list<string>  $variantIds
     * @return Collection<string, InventoryLevel> cheie = variant_id
     */
    private function lockLevelsAtLocation(string $locationId, array $variantIds): Collection
    {
        $pairs = array_map(
            static fn (string $variantId): array => ['variant_id' => $variantId, 'location_id' => $locationId],
            $variantIds,
        );

        return $this->lockLevelPairs($pairs)
            ->mapWithKeys(static fn (InventoryLevel $level): array => [$level->variant_id => $level]);
    }

    /**
     * Generalizare (code review P1-003): mulțimea de perechi (variantă, locație) care
     * trebuie blocate — o singură variantă și mai multe locații (transfer, mișcare), sau
     * mai multe variante la o singură locație (rezervarea/eliberarea unei comenzi).
     * Aceeași convenție de blocare din docblock-ul trait-ului, la scară mai mare.
     *
     * @param  list<array{variant_id: string, location_id: string}>  $pairs
     * @return Collection<string, InventoryLevel> cheie = "{variant_id}:{location_id}"
     */
    private function lockLevelPairs(array $pairs): Collection
    {
        if ($pairs === []) {
            return new Collection;
        }

        $this->ensureLevelsExist($pairs);

        // Potrivire EXACTĂ pe perechi, nu `whereIn` pe fiecare coloană separat — altfel
        // s-ar bloca și rânduri nesolicitate (produsul cartezian variante × locații care
        // există deja în bază, dar n-au fost cerute de apelant).
        return InventoryLevel::query()
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as $pair) {
                    $query->orWhere(function ($pairQuery) use ($pair): void {
                        $pairQuery
                            ->where('variant_id', $pair['variant_id'])
                            ->where('location_id', $pair['location_id']);
                    });
                }
            })
            ->orderBy('variant_id')
            ->orderBy('location_id')
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (InventoryLevel $level): string => "{$level->variant_id}:{$level->location_id}");
    }

    /**
     * Creează rândurile `(variant_id, location_id)` care lipsesc, înainte de blocare:
     * seed-ul (Faza 1) nu garantează o proiecție pentru fiecare pereche variantă/locație,
     * doar pentru cele efectiv mișcate — și nici o comandă nouă, pe o variantă abia
     * creată, nu are garanția uneia la locația de rezervare.
     *
     * Două reguli, fiecare pentru un defect reprodus cu două sesiuni `psql`:
     *  - Rândurile se inserează sortate, într-o singură instrucțiune. Inserate în ordinea
     *    apelantului, două scrieri concurente pe o pereche fără proiecție ajungeau să
     *    țină fiecare câte un rând nou și să-l aștepte pe al celuilalt: deadlock.
     *  - `INSERT … ON CONFLICT DO NOTHING`, nu `create()` într-un `catch`. În PostgreSQL
     *    orice eroare abortează tranzacția, iar aici tranzacția e a întregii cereri (ADR-013):
     *    rândul creat concurent (de o recepție de stoc sau de o a doua linie pe aceeași
     *    variantă) dădea 500 la interogarea următoare, nu „nimic de făcut".
     *
     * `insertOrIgnore` ocolește evenimentele Eloquent, deci `id` și `tenant_id` se
     * completează explicit, cum le-ar fi completat `HasUlids` și `BelongsToTenant`.
     *
     * @param  list<array{variant_id: string, location_id: string}>  $pairs
     */
    private function ensureLevelsExist(array $pairs): void
    {
        $unique = [];

        foreach ($pairs as $pair) {
            $unique["{$pair['variant_id']}:{$pair['location_id']}"] = $pair;
        }

        $variantIds = array_values(array_unique(array_column($unique, 'variant_id')));
        $locationIds = array_values(array_unique(array_column($unique, 'location_id')));

        $existing = InventoryLevel::query()
            ->whereIn('variant_id', $variantIds)
            ->whereIn('location_id', $locationIds)
            ->get(['variant_id', 'location_id'])
            ->map(static fn (InventoryLevel $level): string => "{$level->variant_id}:{$level->location_id}")
            ->all();

        $missing = array_diff(array_keys($unique), $existing);

        if ($missing === []) {
            return;
        }

        // Sortul pe cheia combinată `variant_id:location_id` ordonează identic cu
        // `ORDER BY variant_id, location_id` de mai sus: ambele ULID au lungime fixă,
        // deci `:` cade mereu pe aceeași poziție și nu poate inversa ordinea dintre doi
        // `variant_id` diferiți.
        sort($missing, SORT_STRING);
        $tenantId = TenantScope::requireCurrentTenantId();
        $now = now();

        InventoryLevel::query()->insertOrIgnore(array_map(static function (string $key) use ($unique, $tenantId, $now): array {
            $pair = $unique[$key];

            return [
                'id' => strtolower((string) Str::ulid()),
                'tenant_id' => $tenantId,
                'variant_id' => $pair['variant_id'],
                'location_id' => $pair['location_id'],
                'on_hand' => 0,
                'reserved' => 0,
                'updated_at' => $now,
            ];
        }, $missing));
    }
}
