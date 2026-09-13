<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decizie a proprietarului (2026-09-13) — tema închisă rămâne implicită, dar acum FIX,
 * nu „urmează sistemul": specs.md §15.6 spunea „pe ea se fac captura de portofoliu și
 * demo-ul public", iar `Welcome.tsx` urmând `prefers-color-scheme` pentru un vizitator
 * anonim putea arăta o captură deschisă la un audit cu SO pe temă deschisă. Rămâne
 * valabil doar pentru cine N-A ALES nimic — vezi App\Support\ThemePreference, care nu
 * s-a schimbat: cine alege explicit `light` sau `system` își păstrează alegerea.
 *
 * DOAR implicitul coloanei se schimbă — enum-ul (`system`/`light`/`dark`) rămâne
 * identic, la fel `dismissed_hints` de alături. Un `ALTER COLUMN ... SET DEFAULT`,
 * nu un `change()` de Blueprint: pe Postgres, `enum()` se compilează ca `varchar` cu
 * `CHECK`, iar un `change()` complet ar regenera acea constrângere fără niciun motiv —
 * riscul e strict mai mare decât beneficiul pentru o mutare de un singur cuvânt.
 *
 * Rândurile EXISTENTE nu se ating: `DEFAULT` se aplică doar la următorul `INSERT` fără
 * valoare explicită pentru `theme`. Conturile demo nu au nevoie de o migrație de date
 * separată — `demo:reset` rulează `migrate:fresh` (schemă nouă) urmat de reseed, deci
 * cele 4 conturi + colegii lor apar din nou ca rânduri noi, sub noul implicit (verificat:
 * niciun seeder din `database/seeders/Demo` nu trece `theme` explicit).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users ALTER COLUMN theme SET DEFAULT 'dark'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users ALTER COLUMN theme SET DEFAULT 'system'");
    }
};
