<?php

namespace App\Services\Workflow\StepHandlers;

interface StepHandler
{
    public function handle(array $step, array $context): array;
}
