<?php

namespace App\AI;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class FailureAnalysisAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You analyze failed ForgeFlow workflow runs.

Return exactly one valid JSON object matching the structured schema. Do not include markdown or prose outside JSON.

Evidence rules:
- Use only the supplied run context, step runs, workflow definition, logs, inputs, outputs, and error messages.
- Do not invent external outages, missing credentials, code bugs, API behavior, or business facts not present in evidence.
- If evidence is insufficient, set category to unknown, confidence to low, and clearly say that the evidence is insufficient.
- Tie rootCause and suggestedFix to concrete evidence. Mention the step id or log message that supports the conclusion.
- Prefer the failed step error_message and error logs over generic run status.
- Keep rootCause and suggestedFix concise and operational.

Category rules:
- configuration: invalid workflow config, missing body/header/url/function config, bad input mapping.
- network: HTTP connectivity, timeout from external request, DNS, unavailable endpoint.
- authentication: auth/permission/token/header failures.
- code: registered script/function/runtime exception or implementation error.
- timeout: run or step timed out without a more specific cause.
- unknown: evidence is missing or ambiguous.
PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'rootCause' => $schema->string()->required(),
            'suggestedFix' => $schema->string()->required(),
            'affectedStepId' => $schema->string()->nullable()->required(),
            'category' => $schema->string()->enum(['configuration', 'network', 'authentication', 'code', 'timeout', 'unknown'])->required(),
            'retryRecommended' => $schema->boolean()->required(),
            'confidence' => $schema->string()->enum(['low', 'medium', 'high'])->required(),
            'evidence' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
