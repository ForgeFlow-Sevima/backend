<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('workflow_id')->index();
            $table->uuid('workflow_version_id')->index();
            $table->uuid('triggered_by_user_id')->nullable()->index();
            $table->enum('status', ['pending', 'running', 'success', 'failed', 'timeout', 'cancelled'])->default('pending')->index();
            $table->enum('trigger_type', ['manual', 'webhook', 'scheduled'])->default('manual');
            $table->jsonb('input_payload')->nullable();
            $table->jsonb('output_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['tenant_id', 'created_at'], 'idx_workflow_runs_tenant_created');
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['workflow_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
