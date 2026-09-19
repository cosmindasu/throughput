<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2, code review Faza 3 valul 2 — `variants` n-avea niciun index cu `product_id`: doar
 * PK-ul și `unique(tenant_id, sku)` (migrația de creare). Orice interogare corelată pe
 * `variants.product_id = products.id` (`variants_count`/`low_stock_variants_count` din
 * `ProductList::baseQuery()`, App\Support\Stock\LowStockRule) scana toate variantele
 * tenantului per produs.
 *
 * Măsurat sub RLS, ca `throughput_app`, cu interogarea reală din `ProductList` (tenant
 * Marlin, seed de dev complet, pagina implicită de 50 de produse): ÎNAINTE, planul pentru
 * `low_stock_variants_count` folosea `variants_tenant_id_sku_unique` doar pentru prefixul
 * `tenant_id`, apoi filtra `product_id = products.id` pe fiecare rând găsit —
 * `Rows Removed by Filter: 735` per produs (× 51 execuții), 65,6 ms total. Indexul de mai
 * jos, cu `product_id` pe a doua poziție după `tenant_id` (§7.7 — `tenant_id` coloană de
 * lider), face din ambele subinterogări corelate (`variants_count` și
 * `low_stock_variants_count`) un `Index Scan`/`Index Only Scan` pe exact variantele
 * produsului, nu pe tot tenantul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->index(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'product_id']);
        });
    }
};
