<?php

use App\Services\Workflow\WorkflowDefinitionValidator;
use Illuminate\Validation\ValidationException;

it('returns a topological order for a valid DAG', function () {
    $definition = testWorkflowDefinition([
        'steps' => [
            ['id' => 'a', 'label' => 'A', 'type' => 'delay', 'dependsOn' => [], 'config' => ['durationMs' => 1]],
            ['id' => 'b', 'label' => 'B', 'type' => 'delay', 'dependsOn' => ['a'], 'config' => ['durationMs' => 1]],
            ['id' => 'c', 'label' => 'C', 'type' => 'script', 'dependsOn' => ['b'], 'config' => ['functionName' => 'echoPayload']],
        ],
    ]);

    $validator = app(WorkflowDefinitionValidator::class);

    expect($validator->topologicalOrder($validator->validate($definition)))->toBe(['a', 'b', 'c']);
});

it('rejects duplicate step ids', function () {
    $definition = testWorkflowDefinition([
        'steps' => [
            ['id' => 'a', 'label' => 'A', 'type' => 'delay', 'dependsOn' => [], 'config' => ['durationMs' => 1]],
            ['id' => 'a', 'label' => 'Duplicate A', 'type' => 'delay', 'dependsOn' => [], 'config' => ['durationMs' => 1]],
        ],
    ]);

    app(WorkflowDefinitionValidator::class)->validate($definition);
})->throws(ValidationException::class, 'Step ids must be present and unique.');

it('rejects unknown dependencies', function () {
    $definition = testWorkflowDefinition([
        'steps' => [
            ['id' => 'a', 'label' => 'A', 'type' => 'delay', 'dependsOn' => ['missing'], 'config' => ['durationMs' => 1]],
        ],
    ]);

    app(WorkflowDefinitionValidator::class)->validate($definition);
})->throws(ValidationException::class, 'Unknown dependency [missing].');

it('rejects cyclic dependencies', function () {
    $definition = testWorkflowDefinition([
        'steps' => [
            ['id' => 'a', 'label' => 'A', 'type' => 'delay', 'dependsOn' => ['b'], 'config' => ['durationMs' => 1]],
            ['id' => 'b', 'label' => 'B', 'type' => 'delay', 'dependsOn' => ['a'], 'config' => ['durationMs' => 1]],
        ],
    ]);

    app(WorkflowDefinitionValidator::class)->validate($definition);
})->throws(ValidationException::class, 'Workflow steps must be a directed acyclic graph.');

it('rejects invalid step types and missing required config', function () {
    $invalidType = testWorkflowDefinition([
        'steps' => [
            ['id' => 'a', 'label' => 'A', 'type' => 'unknown', 'dependsOn' => [], 'config' => []],
        ],
    ]);

    expect(fn () => app(WorkflowDefinitionValidator::class)->validate($invalidType))
        ->toThrow(ValidationException::class, 'Unsupported step type.');

    $invalidHttp = testWorkflowDefinition([
        'steps' => [
            ['id' => 'a', 'label' => 'A', 'type' => 'http', 'dependsOn' => [], 'config' => ['method' => 'GET']],
        ],
    ]);

    expect(fn () => app(WorkflowDefinitionValidator::class)->validate($invalidHttp))
        ->toThrow(ValidationException::class, 'This field is required.');
});

it('accepts condition branch targets that point to known steps', function () {
    $definition = testWorkflowDefinition([
        'steps' => [
            ['id' => 'check', 'label' => 'Check', 'type' => 'condition', 'dependsOn' => [], 'config' => ['left' => 'paid', 'operator' => 'equals', 'right' => 'paid', 'onTrue' => ['yes'], 'onFalse' => ['no']]],
            ['id' => 'yes', 'label' => 'Yes', 'type' => 'delay', 'dependsOn' => ['check'], 'config' => ['durationMs' => 1]],
            ['id' => 'no', 'label' => 'No', 'type' => 'delay', 'dependsOn' => ['check'], 'config' => ['durationMs' => 1]],
        ],
    ]);

    expect(app(WorkflowDefinitionValidator::class)->validate($definition)['steps'])->toHaveCount(3);
});
