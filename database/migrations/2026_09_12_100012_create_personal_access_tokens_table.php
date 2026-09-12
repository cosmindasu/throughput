<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela standard Sanctum, cu o singură modificare: `ulidMorphs` în loc de `morphs`.
 * Modelul legat (`users`) are cheie ULID, iar `morphs` ar fi creat un `bigint` —
 * nepotrivirea ar fi apărut abia la primul token emis, în Faza 5.
 *
 * Migrația din pachet e dezactivată în `AppServiceProvider` (`Sanctum::ignoreMigrations()`),
 * altfel ar rula ambele și a doua ar cădea pe „table already exists".
 *
 * Stratul aplicativ vizibil în interfață e `api_tokens` (§18.1, Grupul I), cu scopuri și
 * `tenant_id`; tabela de față rămâne mecanismul de sub el.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->ulidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
