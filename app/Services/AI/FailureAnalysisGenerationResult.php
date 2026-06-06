<?php

namespace App\Services\AI;

readonly class FailureAnalysisGenerationResult
{
    public function __construct(
        public array $analysis,
        public string $promptText,
        public array $inputContext,
        public array $rawResponse,
        public array $usage,
    ) {}
}
