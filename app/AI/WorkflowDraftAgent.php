<?php

namespace App\AI;

use App\Support\MockApiUrls;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class WorkflowDraftAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly int $maxSteps = 20) {}

    public function instructions(): Stringable|string
    {
        $orderUrl = MockApiUrls::order();
        $notificationsUrl = MockApiUrls::notifications();
        $orderStatusUrl = MockApiUrls::orderStatus();

        return <<<PROMPT
You generate ForgeFlow workflow definitions.

Return exactly one valid JSON object matching the structured schema. Do not include markdown, comments, prose, or extra root fields.

Rules:
- Root fields: name, trigger, timeoutMs, retryPolicy, steps.
- trigger must be manual, webhook, or scheduled.
- retryPolicy.backoff must be exponential.
- Step type must be one of: http, delay, condition, script, approval.
- Step ids must be unique kebab-case strings using letters, numbers, underscores, or dashes only.
- dependsOn must only reference existing step ids. Build a directed acyclic graph. Prefer simple readable DAGs.
- Use config for every step.
- Satisfy every concrete user requirement. Do not omit requested steps, channels, approvals, scripts, or branches.
- For parallel work, model parallel execution as sibling steps with the same dependency. Add a final join/summary step that depends on every parallel sibling.

Config by type:
- http: method, url, headers, body, timeoutMs. Use mock endpoints when external systems are implied: {$orderUrl}, {$notificationsUrl}, {$orderStatusUrl}.
- For POST notification steps, always include headers with Content-Type application/json and body.channel, body.recipient, body.message, and body.metadata.
- delay: durationMs.
- condition: left, operator, right, onTrue, onFalse. operator must be equals, not_equals, contains, greater_than, or less_than. Branch arrays contain step ids.
- script: functionName and input. functionName must be echoPayload, mergePayload, or countPreviousOutputs.
- approval: title, description, approvers, timeoutMs. approvers should use roles admin and/or editor.

Use dynamic bindings with {{ input.body.field }} and {{ steps.step-id.body.data.field }} where needed.
Keep outputs practical for immediate testing against ForgeFlow backend validation.
PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'trigger' => $schema->string()->enum(['manual', 'webhook', 'scheduled'])->required(),
            'timeoutMs' => $schema->integer()->required(),
            'retryPolicy' => $schema->object([
                'maxAttempts' => $schema->integer()->required(),
                'backoff' => $schema->string()->enum(['exponential'])->required(),
            ])->required(),
            'steps' => $schema->array()->min(1)->max($this->maxSteps)->items(
                $schema->object([
                    'id' => $schema->string()->required(),
                    'label' => $schema->string()->required(),
                    'type' => $schema->string()->enum(['http', 'delay', 'condition', 'script', 'approval'])->required(),
                    'dependsOn' => $schema->array()->items($schema->string())->required(),
                    'config' => $schema->object()->required(),
                ])->required()
            )->required(),
        ];
    }
}
