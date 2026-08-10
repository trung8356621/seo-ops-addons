<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationEdgeBranch;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationNodeType;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationConditionEngine;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphEdgeResolver;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphSnapshot;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphValidator;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationInputMapper;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;

final class AutomationWorkflowTestService
{
    private const MAX_STEPS = 64;

    public function __construct(
        private readonly AutomationVersionService $versionService,
        private readonly AutomationGraphValidator $graphValidator,
        private readonly AutomationGraphEdgeResolver $edgeResolver,
        private readonly AutomationConditionEngine $conditionEngine,
        private readonly AutomationInputMapper $inputMapper,
        private readonly AutomationActionRegistry $actionRegistry,
        private readonly AutomationRuleMatcher $matcher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $subject
     * @return array<string, mixed>
     */
    public function dryRun(
        AutomationRule $rule,
        array $payload = [],
        array $context = [],
        array $subject = [],
    ): array {
        return $this->simulate($rule, $payload, $context, $subject, executeSafe: false);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $subject
     * @return array<string, mixed>
     */
    public function executeSafe(
        AutomationRule $rule,
        array $payload = [],
        array $context = [],
        array $subject = [],
    ): array {
        return $this->simulate($rule, $payload, $context, $subject, executeSafe: true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $subject
     * @return array<string, mixed>
     */
    private function simulate(
        AutomationRule $rule,
        array $payload,
        array $context,
        array $subject,
        bool $executeSafe,
    ): array {
        $rule->loadMissing(['nodes', 'edges']);

        try {
            $version = $this->versionService->resolveGraphForExecution($rule);
            $snapshot = AutomationGraphSnapshot::fromVersion($rule, $version);
        } catch (\Throwable) {
            $snapshot = AutomationGraphSnapshot::fromLiveRule($rule);
        }

        $errors = $this->graphValidator->validate($rule, $snapshot->nodes, $snapshot->edges);

        $event = $this->buildSyntheticEvent($rule, $payload, $context);
        $sources = $this->matcher->buildSources($event, $subject !== [] ? $subject : [
            'id' => $event->subject_id,
            'type' => $event->subject_type,
        ]);

        $trigger = $snapshot->nodes->first(
            static fn ($n): bool => (string) $n->node_type === AutomationNodeType::Trigger->value && (bool) $n->is_enabled,
        );

        if ($trigger === null) {
            $errors[] = 'Missing enabled trigger node.';
        }

        $steps = [];
        $branches = [];
        $visited = [];
        $currentKey = $trigger !== null ? (string) $trigger->node_key : '';
        $stepCount = 0;

        while ($currentKey !== '' && $stepCount < self::MAX_STEPS) {
            if (isset($visited[$currentKey])) {
                $errors[] = 'Cycle detected during simulation.';
                break;
            }
            $visited[$currentKey] = true;
            $stepCount++;

            $node = $snapshot->findNode($currentKey);
            if ($node === null) {
                $errors[] = "Unknown node [{$currentKey}].";
                break;
            }

            $nodeType = (string) $node->node_type;
            $branchOut = AutomationEdgeBranch::Always->value;
            $simulatedSuccess = true;
            $execution = null;

            if ($nodeType === AutomationNodeType::End->value) {
                $steps[] = $this->stepPayload($node, $branchOut, simulated: true, executeSafe: $executeSafe);
                break;
            }

            if ($nodeType === AutomationNodeType::Condition->value) {
                $conditions = is_array($node->config ?? null) ? ($node->config['conditions'] ?? $node->config) : ($node->config ?? []);
                $matched = $this->conditionEngine->matches(is_array($conditions) ? $conditions : [], $sources);
                $branchOut = $matched ? AutomationEdgeBranch::True->value : AutomationEdgeBranch::False->value;
                $simulatedSuccess = $matched;
            } elseif ($nodeType === AutomationNodeType::Action->value) {
                $actionCode = (string) ($node->action_code ?? '');
                $definition = $this->actionRegistry->has($actionCode) ? $this->actionRegistry->get($actionCode) : null;

                if ($executeSafe && $definition !== null && $definition->supportsTest) {
                    $input = $this->inputMapper->map($node->input_mapping ?? [], $sources);
                    $settings = is_array($node->settings ?? null) ? $node->settings : [];
                    $handler = $this->actionRegistry->resolveHandler($actionCode);
                    $actionContext = new AutomationActionContext(
                        businessEvent: $event,
                        rule: $rule,
                        execution: $this->syntheticExecution($rule),
                        subject: null,
                        subjectData: $sources['subject'] ?? [],
                        siteId: $event->site_id,
                        projectId: $event->project_id,
                        actorId: null,
                        correlationId: 'test-'.($event->event_uuid ?? 'local'),
                        automationDepth: 0,
                        previousOutputs: [],
                        dryRun: true,
                        nodeExecutionId: 0,
                        nodeKey: (string) $node->node_key,
                    );
                    $result = $handler->handle($actionContext, $input, $settings);
                    $simulatedSuccess = $result->success;
                    $execution = [
                        'executed' => true,
                        'success' => $result->success,
                        'message' => $result->message,
                        'output' => $result->output,
                    ];
                } elseif ($executeSafe) {
                    $execution = [
                        'executed' => false,
                        'reason' => $definition === null
                            ? 'unknown_action'
                            : 'action_not_test_safe',
                    ];
                    $simulatedSuccess = true;
                } else {
                    $execution = ['simulated' => true, 'external' => ! ($definition?->supportsTest ?? false)];
                }

                $branchOut = $simulatedSuccess
                    ? AutomationEdgeBranch::Success->value
                    : AutomationEdgeBranch::Failure->value;
            } elseif ($nodeType === AutomationNodeType::Delay->value) {
                if ($executeSafe) {
                    $seconds = (int) (($node->config ?? [])['seconds'] ?? ($node->settings ?? [])['seconds'] ?? 0);
                    $execution = ['executed' => true, 'simulated_delay_seconds' => max(0, $seconds)];
                }
            } elseif ($nodeType === AutomationNodeType::DispatchEvent->value) {
                $execution = ['simulated' => true, 'external' => true];
            }

            $nextEdges = $this->edgeResolver->resolve(
                $snapshot->edges,
                $currentKey,
                $simulatedSuccess,
                $nodeType === AutomationNodeType::Condition->value ? $branchOut : null,
            );

            $nextKeys = array_map(static fn ($e): string => (string) $e->to_node_key, $nextEdges);
            foreach ($nextEdges as $edge) {
                $branches[] = [
                    'from' => $currentKey,
                    'to' => (string) $edge->to_node_key,
                    'branch' => (string) ($edge->branch ?? AutomationEdgeBranch::Always->value),
                ];
            }

            $steps[] = $this->stepPayload(
                $node,
                $branchOut,
                simulated: ! $executeSafe || ($execution['executed'] ?? false) === false,
                executeSafe: $executeSafe,
                execution: $execution,
                next: $nextKeys,
            );

            $currentKey = $nextKeys[0] ?? '';
        }

        if ($stepCount >= self::MAX_STEPS) {
            $errors[] = 'Simulation step limit exceeded.';
        }

        return [
            'mode' => $executeSafe ? 'execute_safe' : 'dry_run',
            'rule_code' => $rule->code,
            'valid' => $errors === [],
            'errors' => $errors,
            'nodes' => $snapshot->nodes->map(static fn ($n): array => [
                'node_key' => (string) $n->node_key,
                'node_type' => (string) $n->node_type,
                'action_code' => $n->action_code,
                'name' => $n->name,
            ])->values()->all(),
            'branches' => $branches,
            'steps' => $steps,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $execution
     * @param  list<string>  $next
     * @return array<string, mixed>
     */
    private function stepPayload(
        object $node,
        string $branchOut,
        bool $simulated,
        bool $executeSafe,
        ?array $execution = null,
        array $next = [],
    ): array {
        return [
            'node_key' => (string) $node->node_key,
            'node_type' => (string) $node->node_type,
            'action_code' => $node->action_code,
            'name' => $node->name,
            'branch_out' => $branchOut,
            'simulated' => $simulated,
            'execute_safe' => $executeSafe,
            'execution' => $execution,
            'next_nodes' => $next,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    private function syntheticExecution(AutomationRule $rule): AutomationExecution
    {
        $execution = new AutomationExecution([
            'execution_uuid' => 'test-exec-'.uniqid('', true),
            'automation_rule_id' => $rule->id,
            'status' => 'processing',
            'attempt' => 1,
            'idempotency_key' => 'test',
        ]);
        $execution->id = 0;

        return $execution;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    private function buildSyntheticEvent(AutomationRule $rule, array $payload, array $context): BusinessEvent
    {
        $event = new BusinessEvent([
            'event_uuid' => 'test-'.uniqid('', true),
            'event_name' => $rule->event_name,
            'subject_type' => $payload['subject_type'] ?? null,
            'subject_id' => isset($payload['subject_id']) ? (int) $payload['subject_id'] : null,
            'site_id' => isset($context['site_id']) ? (int) $context['site_id'] : (isset($payload['site_id']) ? (int) $payload['site_id'] : null),
            'project_id' => isset($context['project_id']) ? (int) $context['project_id'] : null,
            'payload' => $payload,
            'context' => $context,
        ]);
        $event->id = 0;

        return $event;
    }
}
