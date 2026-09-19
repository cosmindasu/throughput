<?php

use Database\Migrations\Concerns\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §13.2 — idempotență PER CHUNK, generică pentru orice acțiune de bulk (code review
 * „P2-001" pe lotul E, decizia proprietarului). Un rând per `(bulk_operation_id, chunk)`
 * EFECTIV aplicat; `App\Jobs\Bulk\ProcessBulkChunkJob::handle()` face `insertOrIgnore` pe
 * acest rând ȘI aplică efectul în ACEEAȘI tranzacție — dacă inserarea întoarce 0 (rândul
 * exista deja, dintr-o rulare anterioară a ACELUIAȘI chunk, redelivrat după un crash între
 * commit și ack, `retry_after`), efectul nu se mai aplică a doua oară.
 *
 * Motivul pentru care un marcaj „ultima operație" pe rândul țintă (varianta anterioară,
 * scoasă din cod) NU e suficient: efectul unei acțiuni de preț depinde de valoarea VECHE
 * a rândului, deci o a doua aplicare a ACELEIAȘI operații (marcaj identic) tot ar dubla
 * efectul dacă nu e blocată înainte de `apply()` — garanția trebuie să fie pe CHUNK, nu pe
 * rândul țintă.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('bulk_operation_chunks', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('bulk_operation_id')->constrained('bulk_operations')->cascadeOnDelete();
            // Indexul chunk-ului, dat de `PlanBulkOperationJob` — stabil și determinist în
            // cadrul UNEI planificări (planificatorul rulează o singură dată per operație,
            // `PlanBulkOperationJob::tries = 1`, deci indexarea nu trebuie să fie stabilă
            // ÎNTRE planificări, doar ÎN cadrul uneia).
            $table->integer('chunk');
            $table->timestamp('created_at')->nullable();
            $table->unique(['bulk_operation_id', 'chunk']);
        });

        $this->enableRls('bulk_operation_chunks');
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_operation_chunks');
    }
};
