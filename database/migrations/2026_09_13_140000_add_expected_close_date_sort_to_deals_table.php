<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coloană generată pentru sortarea pe `expected_close_date`, pereche cu `value_sort`
     * (2026_09_13_090000). Data e nullabilă (§9.2), iar `cursorPaginate()` construiește
     * cursorul din valoarea ultimului rând: când granița de pagină cade pe un deal fără dată,
     * a doua pagină cerea `expected_close_date > NULL` și răspundea 500.
     *
     * Sentinela `9999-12-31` reproduce exact ordinea pe care Postgres o dădea deja coloanei
     * brute (NULL după orice dată la ASC, înaintea lor la DESC), deci fixul nu schimbă nimic
     * vizibil pe prima pagină: un deal fără dată estimată de închidere sortează ca „cel mai
     * îndepărtat". `DealList` expune în URL tot `expected_close_date`.
     *
     * Indexul compus `(tenant_id, expected_close_date_sort)`, cu `tenant_id` pe prima poziție,
     * ca toate indexurile de listă din plan §7.7.
     */
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->date('expected_close_date_sort')->storedAs("COALESCE(expected_close_date, DATE '9999-12-31')");
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->index(['tenant_id', 'expected_close_date_sort']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'expected_close_date_sort']);
            $table->dropColumn('expected_close_date_sort');
        });
    }
};
