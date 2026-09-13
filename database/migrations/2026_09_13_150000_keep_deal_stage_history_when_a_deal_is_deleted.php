<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §9.1/§9.2: `deal_stage_events` e append-only, fără UPDATE și fără DELETE. Două FK-uri îl
     * contraziceau la nivel de bază, unde trait-ul `AppendOnly` nu ajunge:
     *
     * - `deal_id` cu `cascadeOnDelete`: ștergerea unui deal îi ștergea tot istoricul de etape.
     *   Deal-urile trec pe soft delete, iar FK-ul pierde cascada, ca un DELETE fizic scăpat
     *   cândva să fie refuzat, nu să rescrie istoria.
     * - `from_stage_id` cu `nullOnDelete`: ștergerea unei etape ar fi rescris rândurile de
     *   istoric care pleacă din ea. Acum e refuzată, la fel ca pe `to_stage_id`.
     */
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('deal_stage_events', function (Blueprint $table) {
            $table->dropForeign(['deal_id']);
            $table->foreign('deal_id')->references('id')->on('deals');

            $table->dropForeign(['from_stage_id']);
            $table->foreign('from_stage_id')->references('id')->on('stages');
        });
    }

    public function down(): void
    {
        Schema::table('deal_stage_events', function (Blueprint $table) {
            $table->dropForeign(['from_stage_id']);
            $table->foreign('from_stage_id')->references('id')->on('stages')->nullOnDelete();

            $table->dropForeign(['deal_id']);
            $table->foreign('deal_id')->references('id')->on('deals')->cascadeOnDelete();
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
