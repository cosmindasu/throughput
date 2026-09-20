<?php

namespace App\Support\Activity;

/**
 * §17.1 — „`old_values`/`new_values` jsonb, nullabil — Doar câmpurile modificate, nu tot
 * rândul." Sursă unică pentru CE se consideră „modificare de business" demnă de jurnal,
 * folosită pe ambele căi de scriere:
 *
 *  - `App\Observers\ActivityLogObserver` — un singur rând, diff-ul vine gata calculat de
 *    Eloquent (`getChanges()`/`getOriginal()`) pe evenimentul `updated`;
 *  - `App\Support\Activity\BulkChunkActivityRecorder` — un `UPDATE` în masă, pe care
 *    observers Eloquent nu-l văd niciodată (§13.2 pct. 3 din raportul lotului): diff-ul se
 *    calculează manual, comparând un instantaneu ÎNAINTE și unul DUPĂ, citite explicit de
 *    `App\Jobs\Bulk\ProcessBulkChunkJob`.
 *
 * Coloanele TEHNICE (cheie primară, tenant, timestamps) se exclud din diff pe ambele căi:
 * un `UPDATE` (per-rând SAU în masă) atinge mereu `updated_at`, deci fără excludere fiecare
 * rând de jurnal ar purta o „modificare" fără valoare de audit — zgomot, nu semnal.
 */
final class ChangedAttributes
{
    /** @var list<string> */
    private const TECHNICAL = ['id', 'tenant_id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * Diferența dintre două instantanee COMPLETE ale aceluiași rând (cale de BULK) —
     * comparație valoare cu valoare, pe cheile comune, excluzând coloanele tehnice.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null} [old, new],
     *                                                                           redactate,
     *                                                                           `null`
     *                                                                           dacă nimic
     *                                                                           demn de
     *                                                                           jurnal
     *                                                                           n-a diferit
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $newValue) {
            if (in_array($key, self::TECHNICAL, true) || ! array_key_exists($key, $before)) {
                continue;
            }

            $oldValue = $before[$key];

            if ($oldValue === $newValue) {
                continue;
            }

            $old[$key] = $oldValue;
            $new[$key] = $newValue;
        }

        return [
            ExcludedAttributes::redact($old === [] ? null : $old),
            ExcludedAttributes::redact($new === [] ? null : $new),
        ];
    }

    /**
     * Cale de UPDATE per-rând, prin Eloquent (`App\Observers\ActivityLogObserver::updated()`):
     * `$changes` vine deja calculat de `Model::getChanges()` (doar cheile efectiv modificate,
     * valori CAST), `$original` de `Model::getOriginal()` (tot rândul, dinainte de
     * modificare) — mai puțină muncă decât `diff()` de mai sus, care compară două
     * instantanee COMPLETE fiindcă pe calea de bulk nu există un `getChanges()` gata calculat.
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $changes
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    public static function fromEloquentUpdate(array $original, array $changes): array
    {
        $old = [];
        $new = [];

        foreach ($changes as $key => $newValue) {
            if (in_array($key, self::TECHNICAL, true)) {
                continue;
            }

            $old[$key] = $original[$key] ?? null;
            $new[$key] = $newValue;
        }

        return [
            ExcludedAttributes::redact($old === [] ? null : $old),
            ExcludedAttributes::redact($new === [] ? null : $new),
        ];
    }

    /**
     * Atributele unui rând nou-creat SAU șters, gata de scris în `old_values`/`new_values`
     * — exclude coloanele tehnice și redactează, ca `diff()`.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public static function snapshot(array $attributes): ?array
    {
        $filtered = array_diff_key($attributes, array_flip(self::TECHNICAL));

        return ExcludedAttributes::redact($filtered === [] ? null : $filtered);
    }
}
