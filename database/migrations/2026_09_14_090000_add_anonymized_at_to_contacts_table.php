<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RTBF (Art. 17 GDPR, specs.md §20.5) — un contact cu deals/orders asociate (BR-CRM-01)
 * nu se șterge fizic, se anonimizează: `anonymized_at` marchează momentul, rândul (și
 * FK-urile `deals.primary_contact_id` / `orders.contact_id` spre el) rămân intacte. Vezi
 * `App\Support\Contacts\ContactErasure`.
 *
 * RLS deja activ pe `contacts` (migrația de creare) — un `ALTER TABLE ADD COLUMN` nu
 * repetă `enableRls()`, la fel ca `2026_09_13_090000_add_result_path_to_bulk_operations_table.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('opt_out');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
