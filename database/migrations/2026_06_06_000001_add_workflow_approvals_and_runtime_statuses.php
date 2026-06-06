<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE workflow_runs ALTER COLUMN status TYPE varchar(50)');
            DB::statement('ALTER TABLE step_runs ALTER COLUMN status TYPE varchar(50)');
            DB::statement('ALTER TABLE step_runs ALTER COLUMN step_type TYPE varchar(50)');
        }

        Schema::create('workflow_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('workflow_run_id')->index();
            $table->uuid('step_run_id')->index();
            $table->string('status', 50)->default('pending')->index();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->jsonb('approvers')->nullable();
            $table->uuid('decided_by_user_id')->nullable()->index();
            $table->text('decision_note')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs')->cascadeOnDelete();
            $table->foreign('step_run_id')->references('id')->on('step_runs')->cascadeOnDelete();
            $table->foreign('decided_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_approvals');
    }
};
