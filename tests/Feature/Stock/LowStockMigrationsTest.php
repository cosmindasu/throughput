<?php

namespace Tests\Feature\Stock;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Faza 3, valul 2 (plan §17, 1.24) — cele două migrații minore ale acestui lot, verificate
 * direct în catalogul PostgreSQL, nu doar citite din fișierul de migrație: FR-STOCK-02
 * (`variants.low_stock_threshold`) și indexul `(tenant_id, created_at)` pe `orders`, pentru
 * vederea implicită (fără filtru de status) a `OrderList`.
 */
class LowStockMigrationsTest extends TestCase
{
    public function test_variants_has_a_nullable_low_stock_threshold_column(): void
    {
        $column = DB::selectOne(
            "select data_type, is_nullable from information_schema.columns where table_name = 'variants' and column_name = 'low_stock_threshold'"
        );

        $this->assertNotNull($column, 'variants.low_stock_threshold is missing — migration did not run.');
        $this->assertSame('integer', $column->data_type);
        $this->assertSame('YES', $column->is_nullable);
    }

    /**
     * NU redundant cu `orders_tenant_id_status_created_at_index` (migrația de creare, §7.7):
     * acela ordonează `created_at` DUPĂ status, deci nu servește vederea implicită
     * (fără predicat de status) — vezi migrația `2026_09_14_120000_...` și raportul agentului.
     */
    public function test_orders_has_a_tenant_id_created_at_index_for_the_default_view(): void
    {
        $index = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'orders' and indexname = 'orders_tenant_id_created_at_index'"
        );

        $this->assertNotNull($index, 'orders_tenant_id_created_at_index is missing — migration did not run.');
        $this->assertStringContainsString('(tenant_id, created_at)', $index->indexdef);

        // Indexul original, pe status, tot există — asta nu îl înlocuiește, îl completează.
        $statusIndex = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'orders' and indexname = 'orders_tenant_id_status_created_at_index'"
        );
        $this->assertNotNull($statusIndex);
    }
}
