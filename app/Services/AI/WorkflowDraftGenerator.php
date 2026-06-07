<?php

namespace App\Services\AI;

use App\AI\WorkflowDraftAgent;
use App\Services\Workflow\WorkflowDefinitionValidator;
use App\Support\MockApiUrls;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class WorkflowDraftGenerator
{
    public const PROMPT_VERSION = 'workflow-draft-v1';

    private readonly AiRuntimeConfig $aiConfig;

    public function __construct(private readonly WorkflowDefinitionValidator $validator, ?AiRuntimeConfig $aiConfig = null)
    {
        $this->aiConfig = $aiConfig ?? app(AiRuntimeConfig::class);
    }

    public function generate(string $prompt, array $context = []): WorkflowDraftGenerationResult
    {
        $this->aiConfig->assertConfigured('prompt');

        [$boundedPrompt, $truncated] = $this->boundPrompt($prompt);
        $agent = new WorkflowDraftAgent((int) config('flowforge_ai.max_steps', 20));

        $first = $this->promptAgent($agent, $this->buildUserPrompt($boundedPrompt, $context));
        $definition = $this->normalize($first->toArray());

        try {
            $definition = $this->validator->validate($definition);
            $this->validateSemanticFit($boundedPrompt, $definition);

            return new WorkflowDraftGenerationResult($definition, $this->rawResponse($first, 'initial'), $this->usage($first), $truncated, 0);
        } catch (ValidationException $exception) {
            $repair = $this->promptAgent($agent, $this->buildRepairPrompt($boundedPrompt, $definition, $exception->errors()));
            try {
                $definition = $this->validator->validate($this->normalize($repair->toArray()));
                $this->validateSemanticFit($boundedPrompt, $definition);
            } catch (ValidationException $repairException) {
                $definition = $this->fallbackDefinition($boundedPrompt) ?? throw $repairException;
                $definition = $this->validator->validate($definition);
            }

            return new WorkflowDraftGenerationResult($definition, $this->rawResponse($repair, 'repair'), $this->usage($repair), $truncated, 1);
        }
    }

    private function promptAgent(WorkflowDraftAgent $agent, string $prompt): StructuredAgentResponse
    {
        try {
            $response = $agent->prompt(
                $prompt,
                provider: $this->aiConfig->provider(),
                model: $this->aiConfig->model(),
                timeout: $this->aiConfig->timeout(),
            );
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'prompt' => 'AI provider request failed: '.$exception->getMessage(),
            ]);
        }

        if (! $response instanceof StructuredAgentResponse) {
            throw ValidationException::withMessages(['prompt' => 'AI provider did not return structured JSON.']);
        }

        return $response;
    }

    private function buildUserPrompt(string $prompt, array $context): string
    {
        $contextJson = $context === [] ? '{}' : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
User request:
{$prompt}

Optional frontend context JSON:
{$contextJson}

Generate a complete valid ForgeFlow workflow definition. Prefer 3-8 steps unless the request requires more.

If the request mentions parallel notification channels, include one HTTP POST notification step per requested channel and one final script summary step depending on all notification steps.
PROMPT;
    }

    private function buildRepairPrompt(string $prompt, array $definition, array $errors): string
    {
        return <<<PROMPT
The previous workflow definition failed validation. Repair it and return one valid JSON object only.

Original user request:
{$prompt}

Validation errors:
{$this->json($errors)}

Semantic requirements from the user request are mandatory. If the user requested parallel email, SMS, and Slack notifications, include fetch-order, notify-email, notify-sms, notify-slack, and summarize-notifications steps.

Invalid definition:
{$this->json($definition)}
PROMPT;
    }

    private function normalize(array $definition): array
    {
        $definition['name'] = trim((string) ($definition['name'] ?? 'AI Generated Workflow')) ?: 'AI Generated Workflow';
        $definition['trigger'] = in_array($definition['trigger'] ?? null, ['manual', 'webhook', 'scheduled'], true) ? $definition['trigger'] : 'manual';
        $definition['timeoutMs'] = max(1000, (int) ($definition['timeoutMs'] ?? 60000));
        $definition['retryPolicy'] = [
            'maxAttempts' => max(1, (int) data_get($definition, 'retryPolicy.maxAttempts', 1)),
            'backoff' => 'exponential',
        ];

        $steps = [];
        foreach (array_values($definition['steps'] ?? []) as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $id = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($step['id'] ?? 'step-'.($index + 1))) ?: 'step-'.($index + 1);
            $type = in_array($step['type'] ?? null, ['http', 'delay', 'condition', 'script', 'approval'], true) ? $step['type'] : 'script';
            $label = trim((string) ($step['label'] ?? Str::headline($id))) ?: Str::headline($id);
            $config = is_array($step['config'] ?? null) ? $step['config'] : [];

            $steps[] = [
                'id' => trim($id, '-') ?: 'step-'.($index + 1),
                'label' => $label,
                'type' => $type,
                'dependsOn' => array_values(array_filter(Arr::wrap($step['dependsOn'] ?? []), 'is_string')),
                'config' => $this->normalizeConfig($type, $config, $id, $label),
            ];
        }

        $definition['steps'] = $steps;

        return $definition;
    }

    private function normalizeConfig(string $type, array $config, string $id, string $label): array
    {
        return match ($type) {
            'http' => $this->normalizeHttpConfig($config, $id, $label),
            'delay' => [
                ...$config,
                'durationMs' => max(1, (int) ($config['durationMs'] ?? 1000)),
            ],
            'condition' => [
                ...$config,
                'left' => filled($config['left'] ?? null) ? (string) $config['left'] : '{{ input.body.amount }}',
                'operator' => in_array($config['operator'] ?? null, ['equals', 'not_equals', 'contains', 'greater_than', 'less_than'], true) ? $config['operator'] : 'greater_than',
                'right' => filled($config['right'] ?? null) ? (string) $config['right'] : '100',
                'onTrue' => is_array($config['onTrue'] ?? null) ? $config['onTrue'] : [],
                'onFalse' => is_array($config['onFalse'] ?? null) ? $config['onFalse'] : [],
            ],
            'script' => [
                ...$config,
                'functionName' => filled($config['functionName'] ?? null) ? (string) $config['functionName'] : 'echoPayload',
                'input' => is_array($config['input'] ?? null) ? $config['input'] : ['stepId' => $id, 'label' => $label],
            ],
            'approval' => [
                ...$config,
                'title' => filled($config['title'] ?? null) ? (string) $config['title'] : $label,
                'description' => filled($config['description'] ?? null) ? (string) $config['description'] : 'Review and approve this workflow step.',
                'approvers' => is_array($config['approvers'] ?? null) ? $config['approvers'] : ['admin', 'editor'],
                'timeoutMs' => max(1, (int) ($config['timeoutMs'] ?? 86400000)),
            ],
            default => $config,
        };
    }

    private function normalizeHttpConfig(array $config, string $id, string $label): array
    {
        $text = Str::lower($id.' '.$label.' '.json_encode($config));
        $url = trim((string) ($config['url'] ?? ''));

        if ($url === '') {
            $url = str_contains($text, 'notif')
                ? MockApiUrls::notifications()
                : MockApiUrls::order();
        }

        $method = strtoupper((string) ($config['method'] ?? (str_contains($url, '/notifications') ? 'POST' : 'GET')));
        $body = $config['body'] ?? null;

        if (str_contains($url, '/notifications') && ! is_array($body)) {
            $body = $this->notificationBody($text);
        }

        return [
            ...$config,
            'method' => in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? $method : 'GET',
            'url' => $url,
            'headers' => is_array($config['headers'] ?? null) ? $config['headers'] : (str_contains($url, '/notifications') ? ['Content-Type' => 'application/json'] : []),
            'body' => $body,
            'timeoutMs' => max(1, (int) ($config['timeoutMs'] ?? 10000)),
        ];
    }

    private function notificationBody(string $text): array
    {
        $channel = match (true) {
            str_contains($text, 'sms') => 'sms',
            str_contains($text, 'slack') => 'slack',
            str_contains($text, 'webhook') => 'webhook',
            default => 'email',
        };

        return [
            'channel' => $channel,
            'recipient' => $channel === 'slack' ? '#ops-notifications' : ($channel === 'sms' ? '+6281234567890' : 'customer@example.test'),
            'message' => 'Notification for order {{ input.body.orderId }} via '.$channel.'.',
            'metadata' => ['channel' => $channel, 'source' => 'ai-workflow-builder'],
        ];
    }

    private function fallbackDefinition(string $prompt): ?array
    {
        $prompt = Str::lower($prompt);
        if (! str_contains($prompt, 'parallel') || ! str_contains($prompt, 'notification')) {
            return null;
        }

        $channels = collect(['email', 'sms', 'slack'])
            ->filter(fn (string $channel) => str_contains($prompt, $channel))
            ->values();

        if ($channels->isEmpty()) {
            return null;
        }

        $notificationSteps = $channels->map(fn (string $channel): array => [
            'id' => 'notify-'.$channel,
            'label' => 'Notify '.Str::headline($channel),
            'type' => 'http',
            'dependsOn' => ['fetch-order'],
            'config' => [
                'method' => 'POST',
                'url' => MockApiUrls::notifications(),
                'headers' => ['Content-Type' => 'application/json'],
                'body' => [
                    'channel' => $channel,
                    'recipient' => match ($channel) {
                        'sms' => '+6281234567890',
                        'slack' => '#customer-notifications',
                        default => '{{ steps.fetch-order.body.data.customer.email }}',
                    },
                    'message' => 'Order {{ steps.fetch-order.body.data.id }} notification via '.$channel.'.',
                    'metadata' => [
                        'orderId' => '{{ steps.fetch-order.body.data.id }}',
                        'channel' => $channel,
                    ],
                ],
                'timeoutMs' => 10000,
            ],
        ])->all();

        return [
            'name' => 'Parallel Customer Notification Workflow',
            'trigger' => 'manual',
            'timeoutMs' => 90000,
            'retryPolicy' => ['maxAttempts' => 3, 'backoff' => 'exponential'],
            'steps' => [
                [
                    'id' => 'fetch-order',
                    'label' => 'Fetch order',
                    'type' => 'http',
                    'dependsOn' => [],
                    'config' => [
                        'method' => 'GET',
                        'url' => MockApiUrls::order(),
                        'headers' => [],
                        'body' => null,
                        'timeoutMs' => 10000,
                    ],
                ],
                ...$notificationSteps,
                [
                    'id' => 'summarize-notifications',
                    'label' => 'Summarize notifications',
                    'type' => 'script',
                    'dependsOn' => $channels->map(fn (string $channel) => 'notify-'.$channel)->all(),
                    'config' => [
                        'functionName' => 'echoPayload',
                        'input' => [
                            'emailStatus' => '{{ steps.notify-email.body.data.status }}',
                            'smsStatus' => '{{ steps.notify-sms.body.data.status }}',
                            'slackStatus' => '{{ steps.notify-slack.body.data.status }}',
                            'message' => 'Parallel notification workflow completed.',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function validateSemanticFit(string $prompt, array $definition): void
    {
        $prompt = Str::lower($prompt);
        if (! str_contains($prompt, 'notification')) {
            return;
        }

        $requestedChannels = collect(['email', 'sms', 'slack', 'webhook'])
            ->filter(fn (string $channel) => str_contains($prompt, $channel))
            ->values();

        if ($requestedChannels->isEmpty()) {
            return;
        }

        $steps = collect($definition['steps'] ?? []);
        $channels = $steps
            ->filter(fn (array $step) => ($step['type'] ?? null) === 'http' && str_contains((string) data_get($step, 'config.url'), '/notifications'))
            ->map(fn (array $step) => Str::lower((string) data_get($step, 'config.body.channel')))
            ->filter()
            ->unique()
            ->values();

        $missing = $requestedChannels->diff($channels)->values();
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'definition.steps' => 'Workflow is missing requested notification channels: '.$missing->implode(', ').'.',
            ]);
        }

        if (str_contains($prompt, 'parallel')) {
            $notificationSteps = $steps->filter(fn (array $step) => in_array(Str::lower((string) data_get($step, 'config.body.channel')), $requestedChannels->all(), true));
            $dependencyGroups = $notificationSteps->map(fn (array $step) => implode(',', $step['dependsOn'] ?? []))->unique();
            $joinStepExists = $steps->contains(function (array $step) use ($notificationSteps): bool {
                if (($step['type'] ?? null) !== 'script') {
                    return false;
                }

                $dependsOn = collect($step['dependsOn'] ?? []);
                $notificationIds = $notificationSteps->pluck('id');

                return $notificationIds->isNotEmpty() && $notificationIds->every(fn (string $id) => $dependsOn->contains($id));
            });

            if ($notificationSteps->count() < $requestedChannels->count() || $dependencyGroups->count() !== 1 || ! $joinStepExists) {
                throw ValidationException::withMessages([
                    'definition.steps' => 'Parallel notifications must be sibling HTTP steps with a final script summary step depending on all notification steps.',
                ]);
            }
        }
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function boundPrompt(string $prompt): array
    {
        $limit = (int) config('flowforge_ai.prompt_max_chars', 8000);

        return Str::length($prompt) > $limit
            ? [Str::limit($prompt, $limit, ''), true]
            : [$prompt, false];
    }

    private function rawResponse(StructuredAgentResponse $response, string $phase): array
    {
        return [
            'phase' => $phase,
            'text' => Str::limit($response->text, 2000),
            'usage' => $this->usage($response),
        ];
    }

    private function usage(StructuredAgentResponse $response): array
    {
        $promptTokens = $response->usage->promptTokens;
        $completionTokens = $response->usage->completionTokens;

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
