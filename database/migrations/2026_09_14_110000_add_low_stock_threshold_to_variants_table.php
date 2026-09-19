<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-STOCK-02 (specs.md §10.7) — alertă „Low stock" pe variante. `variants` nu avea prag;
 * `null` = fără alertă (nu 0 implicit — un prag de 0 ar fi o alegere de business validă,
 * distinctă de „nesetat"). Regula completă („nenul ȘI disponibil < prag") trăiește într-un
 * singur loc, `App\Support\Stock\LowStockRule`.
 *
 * Fără constrângere CHECK la nivel de bază — la fel ca `price`/`cost`/`weight` pe aceeași
 * tabelă (migrația de creare), validarea `min:0` stă în `Store/UpdateVariantRequest`.
 * RLS deja activ pe `variants` (migrația de creare) — un `ALTER TABLE ADD COLUMN` nu repetă
 * `enableRls()`, la fel ca `2026_09_14_090000_add_anonymized_at_to_contacts_table.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->integer('low_stock_threshold')->nullable()->after('weight');
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->dropColumn('low_stock_threshold');
        });
    }
};
