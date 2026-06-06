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
        Schema::create('ai_failure_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('workflow_run_id')->index();
            $table->uuid('step_run_id')->nullable()->index();
            $table->uuid('requested_by_user_id')->nullable()->index();
            $table->string('provider', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('prompt_version', 50)->nullable();
            $table->text('prompt_text')->nullable();
            $table->jsonb('input_context')->nullable();
            $table->text('summary')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('suggested_fix')->nullable();
            $table->enum('category', ['configuration', 'network', 'authentication', 'code', 'timeout', 'unknown'])->default('unknown')->index();
            $table->boolean('retry_recommended')->nullable();
            $table->enum('confidence', ['low', 'medium', 'high'])->nullable();
            $table->jsonb('raw_response')->nullable();
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('total_tokens')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['workflow_run_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_failure_analyses');
    }
};
