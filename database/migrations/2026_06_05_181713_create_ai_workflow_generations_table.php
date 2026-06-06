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
        Schema::create('ai_workflow_generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('requested_by_user_id')->index();
            $table->string('provider', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->text('user_prompt');
            $table->string('prompt_version', 50)->nullable();
            $table->text('system_prompt')->nullable();
            $table->jsonb('generated_definition')->nullable();
            $table->jsonb('validation_errors')->nullable();
            $table->enum('status', ['success', 'invalid_output', 'failed'])->index();
            $table->jsonb('raw_response')->nullable();
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('total_tokens')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_workflow_generations');
    }
};
