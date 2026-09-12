<?php

use Database\Migrations\Concerns\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('account_id')->constrained('accounts');
            $table->foreignUlid('primary_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('pipeline_id')->constrained('pipelines');
            $table->foreignUlid('stage_id')->constrained('stages');
            $table->foreignUlid('owner_user_id')->constrained('users');
            $table->string('title');
            $table->decimal('value', 12, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->date('expected_close_date')->nullable();
            $table->enum('status', ['open', 'won', 'lost'])->default('open');
            $table->string('lost_reason')->nullable();
            $table->foreignUlid('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['tenant_id', 'stage_id']);
            $table->index(['tenant_id', 'owner_user_id']);
            $table->index(['tenant_id', 'account_id']);
        });

        $this->enableRls('deals');
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
