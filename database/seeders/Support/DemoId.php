<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Str;

/**
 * ID-ul unui rând scris în bloc de seed-ul demo.
 *
 * Identic cu `HasUlids::newUniqueId()`: ULID cu litere mici. Scrierea în bloc ocolește
 * evenimentele Eloquent (vezi ChunkedWriter), deci id-ul îl generează apelantul — iar
 * `Str::ulid()` direct dă MAJUSCULE. Măsurat pe setul demo: 8.000 de conturi, 10.385 de
 * contacte, 4.400 de deals și 50.000 de comenzi cu majuscule, alături de users/stages/
 * memberships create prin Eloquent, cu litere mici. Amestecul strica tăcut orice comparație
 * după o normalizare (`Str::lower` pe un id primit din URL) și ordinea după `id` folosită ca
 * departajare la paginarea pe cursor.
 */
final class DemoId
{
    public static function next(): string
    {
        return strtolower((string) Str::ulid());
    }
}
