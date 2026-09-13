<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P3-a (code review pachetul „contacte") — plasă în bază pentru „un singur primary per
 * cont", dincolo de `PrimaryContactAssignment` (aplicativ, cu `lockForUpdate()`).
 * Regula trăiește deja corect în cod; indexul e a doua plasă, în același spirit ca
 * ADR-003 (global scope + RLS): o eroare de aplicație nu trebuie să poată produce doi
 * primari pe același cont.
 *
 * Index UNIC PARȚIAL, nu un unique simplu pe `(tenant_id, account_id)`: contul poate
 * avea oricâte contacte NEprimary — restricția se aplică DOAR rândurilor cu
 * `is_primary = true`. `WHERE is_primary` (fără `= true`): Postgres acceptă direct o
 * coloană boolean într-un `WHERE` de index parțial.
 *
 * Nu include `account_id IS NOT NULL` explicit: un index unic ignoră rândurile cu
 * `NULL` pe oricare coloană indexată (semantica standard SQL de `NULL <> NULL`), deci
 * contactele fără cont (leaduri brute, §8.1) nu intră oricum în conflict între ele.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX contacts_single_primary_per_account ON contacts (tenant_id, account_id) WHERE is_primary'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contacts_single_primary_per_account');
    }
};
