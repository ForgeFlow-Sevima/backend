<?php

namespace App\Console\Commands;

use App\Services\Workflow\ScheduledTriggerService;
use Illuminate\Console\Command;

class RunWorkflowSchedulerCommand extends Command
{
    protected $signature = 'workflow:scheduler:run';

    protected $description = 'Run due FlowForge scheduled workflow triggers.';

    public function handle(ScheduledTriggerService $service): int
    {
        $runs = $service->runDue();
        $this->info('Scheduled workflow runs created: '.count($runs));

        return self::SUCCESS;
    }
}
