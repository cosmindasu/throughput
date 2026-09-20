<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot I18N, Val 1 (ADR-022, specs.md §15.8 FR-I18N-01, plan-implementare.md „Lot I18N").
 *
 * Aceeași tabelă și același raționament ca `users.theme` (0001_01_01_000000_create_users_table):
 * preferința de limbă e a PERSOANEI, nu a organizației — nu se schimbă la comutarea de
 * workspace (FR-I18N-01, simetric cu FR-PREF-02). `users` rămâne identitate GLOBALĂ, fără
 * `tenant_id`, fără RLS (ADR-014).
 *
 * Spre deosebire de `theme`, aici NU există o a treia stare de tip „System" — doar
 * `en`/`fr` (ADR-022, App\Support\LocalePreference). Implicit `en`: engleza rămâne limba
 * implicită deliberată a aplicației (ADR-022), franceza fiind a doua limbă adăugată ca
 * demonstrație de competență i18n, nu o piață francofonă.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('locale', ['en', 'fr'])->default('en')->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
