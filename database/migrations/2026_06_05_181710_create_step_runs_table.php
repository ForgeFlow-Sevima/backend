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
        Schema::create('step_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('workflow_run_id')->index();
            $table->string('step_id', 100)->index();
            $table->string('step_name', 150)->nullable();
            $table->enum('step_type', ['http', 'delay', 'condition', 'script']);
            $table->enum('status', ['pending', 'running', 'success', 'failed', 'retrying', 'skipped'])->default('pending')->index();
            $table->jsonb('depends_on')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->integer('max_retries')->default(0);
            $table->integer('backoff_seconds')->default(1);
            $table->jsonb('input_payload')->nullable();
            $table->jsonb('output_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['workflow_run_id', 'step_id']);
            $table->index(['workflow_run_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('step_runs');
    }
};
