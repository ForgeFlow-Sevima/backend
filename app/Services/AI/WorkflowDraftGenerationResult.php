<?php

namespace App\Services\AI;

readonly class WorkflowDraftGenerationResult
{
    public function __construct(
        public array $definition,
        public array $rawResponse,
        public array $usage,
        public bool $truncated,
        public int $repairAttempts,
    ) {}
}
