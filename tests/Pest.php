<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Cazul de bază
|--------------------------------------------------------------------------
|
| Tot ce e sub `tests/` folosește `Tests\TestCase`, deci migrațiile rulează pe
| `pgsql_migrations` (rolul cu BYPASSRLS, proprietarul tabelelor) iar testele pe
| `throughput_app` (fără BYPASSRLS). Separarea asta e condiția ca RLS să fie activ
| în suită: PostgreSQL nu aplică politici proprietarului tabelei, deci dacă testele
| ar migra cu propriul rol, suita de izolare ar fi trecut verde pe o plasă inexistentă.
| Vezi Tests\Concerns\RefreshesTenantDatabase.
|
| Fișierele din `tests/Unit/` scrise ca clase PHPUnit (ArchitectureTest) nu sunt
| afectate de `uses()` — el leagă doar clasa de bază a funcțiilor Pest.
|
*/

uses(TestCase::class)->in('Feature', 'Unit');
