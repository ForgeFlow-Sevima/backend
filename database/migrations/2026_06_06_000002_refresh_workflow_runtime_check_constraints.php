<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE workflow_runs DROP CONSTRAINT IF EXISTS workflow_runs_status_check');
        DB::statement('ALTER TABLE step_runs DROP CONSTRAINT IF EXISTS step_runs_status_check');
        DB::statement('ALTER TABLE step_runs DROP CONSTRAINT IF EXISTS step_runs_step_type_check');
        DB::statement('ALTER TABLE step_run_attempts DROP CONSTRAINT IF EXISTS step_run_attempts_status_check');

        DB::statement("ALTER TABLE workflow_runs ADD CONSTRAINT workflow_runs_status_check CHECK (status IN ('pending', 'running', 'waiting_approval', 'success', 'failed', 'timeout', 'cancelled'))");
        DB::statement("ALTER TABLE step_runs ADD CONSTRAINT step_runs_status_check CHECK (status IN ('pending', 'running', 'waiting_approval', 'success', 'failed', 'retrying', 'skipped'))");
        DB::statement("ALTER TABLE step_runs ADD CONSTRAINT step_runs_step_type_check CHECK (step_type IN ('http', 'delay', 'condition', 'script', 'approval'))");
        DB::statement("ALTER TABLE step_run_attempts ADD CONSTRAINT step_run_attempts_status_check CHECK (status IN ('pending', 'running', 'success', 'failed', 'retrying', 'skipped'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE workflow_runs DROP CONSTRAINT IF EXISTS workflow_runs_status_check');
        DB::statement('ALTER TABLE step_runs DROP CONSTRAINT IF EXISTS step_runs_status_check');
        DB::statement('ALTER TABLE step_runs DROP CONSTRAINT IF EXISTS step_runs_step_type_check');
        DB::statement('ALTER TABLE step_run_attempts DROP CONSTRAINT IF EXISTS step_run_attempts_status_check');

        DB::statement("ALTER TABLE workflow_runs ADD CONSTRAINT workflow_runs_status_check CHECK (status IN ('pending', 'running', 'success', 'failed', 'timeout', 'cancelled'))");
        DB::statement("ALTER TABLE step_runs ADD CONSTRAINT step_runs_status_check CHECK (status IN ('pending', 'running', 'success', 'failed', 'retrying', 'skipped'))");
        DB::statement("ALTER TABLE step_runs ADD CONSTRAINT step_runs_step_type_check CHECK (step_type IN ('http', 'delay', 'condition', 'script'))");
        DB::statement("ALTER TABLE step_run_attempts ADD CONSTRAINT step_run_attempts_status_check CHECK (status IN ('pending', 'running', 'success', 'failed', 'retrying', 'skipped'))");
    }
};
