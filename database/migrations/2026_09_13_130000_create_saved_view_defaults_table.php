<?php

use Database\Migrations\Concerns\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-VIEW-02 — „vedere implicită per utilizator, per listă, per workspace". Nu există în
 * schema de Faza 1 (doar `saved_views`), deci tabelă nouă, nu o coloană pe `saved_views`:
 * implicitul e o alegere a UTILIZATORULUI curent, nu un atribut al vederii însăși — aceeași
 * vedere „Team" poate fi implicită pentru un Agent și ignorată de un altul.
 *
 * `saved_view_id` NULABIL, cu `nullOnDelete()`, deliberat — nu pentru „utilizatorul și-a
 * scos implicitul" (acțiunea aia ȘTERGE rândul, în `SavedViewController::setDefault()`), ci
 * pentru scenariul din FR-VIEW-02: o vedere „Team" ștearsă de un Manager lasă rândul viu,
 * cu `saved_view_id = NULL` — PostgreSQL face asta la nivelul integrității referențiale,
 * deci nu depinde de cine a rulat `DELETE` sau de contextul lui de tenant. Rândul orfan e
 * exact semnalul pe care `SavedViewDefaultRedirect` îl citește o singură dată (mesajul
 * discret), apoi îl șterge.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('saved_view_defaults', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('resource_type', ['accounts', 'contacts', 'deals', 'orders', 'products', 'invoices']);
            $table->foreignUlid('saved_view_id')->nullable()->constrained('saved_views')->nullOnDelete();
            $table->timestamps();
            // Unicitate pe (tenant, user, resursă): un singur implicit per listă per om.
            // `tenant_id` conduce, ca la orice index compus pe o tabelă cu RLS (ADR-003 addendum).
            $table->unique(['tenant_id', 'user_id', 'resource_type']);
        });

        $this->enableRls('saved_view_defaults');
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_view_defaults');
    }
};
