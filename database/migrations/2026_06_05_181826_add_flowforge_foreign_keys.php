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
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('current_version_id')->references('id')->on('workflow_versions')->nullOnDelete();
        });

        Schema::table('workflow_versions', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_id')->references('id')->on('workflows')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_id')->references('id')->on('workflows')->cascadeOnDelete();
            $table->foreign('workflow_version_id')->references('id')->on('workflow_versions')->cascadeOnDelete();
            $table->foreign('triggered_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('step_runs', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs')->cascadeOnDelete();
        });

        Schema::table('step_run_attempts', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs')->cascadeOnDelete();
            $table->foreign('step_run_id')->references('id')->on('step_runs')->cascadeOnDelete();
        });

        Schema::table('execution_logs', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs')->cascadeOnDelete();
            $table->foreign('step_run_id')->references('id')->on('step_runs')->nullOnDelete();
        });

        Schema::table('webhook_triggers', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_id')->references('id')->on('workflows')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('scheduled_triggers', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_id')->references('id')->on('workflows')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('ai_workflow_generations', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('requested_by_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('ai_failure_analyses', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs')->cascadeOnDelete();
            $table->foreign('step_run_id')->references('id')->on('step_runs')->nullOnDelete();
            $table->foreign('requested_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('sse_access_tokens', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropForeign(['user_id']));
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('sse_access_tokens', fn (Blueprint $table) => $table->dropForeign(['user_id']));
        Schema::table('sse_access_tokens', fn (Blueprint $table) => $table->dropForeign(['workflow_run_id']));
        Schema::table('sse_access_tokens', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('ai_failure_analyses', fn (Blueprint $table) => $table->dropForeign(['requested_by_user_id']));
        Schema::table('ai_failure_analyses', fn (Blueprint $table) => $table->dropForeign(['step_run_id']));
        Schema::table('ai_failure_analyses', fn (Blueprint $table) => $table->dropForeign(['workflow_run_id']));
        Schema::table('ai_failure_analyses', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('ai_workflow_generations', fn (Blueprint $table) => $table->dropForeign(['requested_by_user_id']));
        Schema::table('ai_workflow_generations', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('scheduled_triggers', fn (Blueprint $table) => $table->dropForeign(['created_by']));
        Schema::table('scheduled_triggers', fn (Blueprint $table) => $table->dropForeign(['workflow_id']));
        Schema::table('scheduled_triggers', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('webhook_triggers', fn (Blueprint $table) => $table->dropForeign(['created_by']));
        Schema::table('webhook_triggers', fn (Blueprint $table) => $table->dropForeign(['workflow_id']));
        Schema::table('webhook_triggers', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('execution_logs', fn (Blueprint $table) => $table->dropForeign(['step_run_id']));
        Schema::table('execution_logs', fn (Blueprint $table) => $table->dropForeign(['workflow_run_id']));
        Schema::table('execution_logs', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('step_run_attempts', fn (Blueprint $table) => $table->dropForeign(['step_run_id']));
        Schema::table('step_run_attempts', fn (Blueprint $table) => $table->dropForeign(['workflow_run_id']));
        Schema::table('step_run_attempts', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('step_runs', fn (Blueprint $table) => $table->dropForeign(['workflow_run_id']));
        Schema::table('step_runs', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('workflow_runs', fn (Blueprint $table) => $table->dropForeign(['triggered_by_user_id']));
        Schema::table('workflow_runs', fn (Blueprint $table) => $table->dropForeign(['workflow_version_id']));
        Schema::table('workflow_runs', fn (Blueprint $table) => $table->dropForeign(['workflow_id']));
        Schema::table('workflow_runs', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('workflow_versions', fn (Blueprint $table) => $table->dropForeign(['created_by']));
        Schema::table('workflow_versions', fn (Blueprint $table) => $table->dropForeign(['workflow_id']));
        Schema::table('workflow_versions', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('workflows', fn (Blueprint $table) => $table->dropForeign(['current_version_id']));
        Schema::table('workflows', fn (Blueprint $table) => $table->dropForeign(['updated_by']));
        Schema::table('workflows', fn (Blueprint $table) => $table->dropForeign(['created_by']));
        Schema::table('workflows', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
    }
};
