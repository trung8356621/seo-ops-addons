<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Presentation;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationRuleClassification;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry;
use Omnichannel\Addons\Content\Extension\Builtin\ContentPipelines\Definitions\ArticlePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\ImprovePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\ProductPipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\RewritePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\TranslatePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Contracts\PipelineDefinitionInterface;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectOperation;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only projection of registered Automation / Content Project / AI pipelines.
 * Source of truth = DB rules + registries + pipeline definitions — no fake flows.
 */
final class AutomationFlowCatalog
{
    /** @var list<string> */
    private const LIFECYCLE_CAPABILITIES = [
        'content_project.create',
        'content_project.generate',
        'content_project.rerun',
        'content_project.stop_execution',
        'content_project.resume_execution',
        'content_project.start_review',
        'content_project.approve',
        'content_project.schedule',
        'content_project.auto_schedule',
        'content_project.unschedule',
        'content_project.publish_now',
        'content_project.retry_publish',
        'content_project.skip_publish',
        'content_project.cancel_publish',
        'content_project.archive',
        'content_project.restore',
        'content_project.sync_items',
    ];

    public function __construct(
        private readonly AutomationFlowPresentationRegistry $presentation,
        private readonly BusinessEventRegistry $events,
        private readonly ContentProjectCapabilityRegistry $capabilities,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listFlows(
        ?string $category = null,
        ?string $eventName = null,
        ?string $health = null,
    ): array {
        $flows = $this->attachLatestStatuses(array_merge(
            $this->flowsFromRules(),
            $this->flowsFromOrphanEvents(),
            $this->flowsFromContentProjectCapabilities(),
            $this->flowsFromContentPipelines(),
        ));

        return array_values(array_filter(
            $flows,
            function (array $flow) use ($category, $eventName, $health): bool {
                if ($category !== null && $category !== '' && ($flow['category'] ?? '') !== $category) {
                    return false;
                }

                if ($eventName !== null && $eventName !== '' && ($flow['event_name'] ?? '') !== $eventName) {
                    return false;
                }

                if ($health === 'never' && ($flow['last_status'] ?? null) !== null) {
                    return false;
                }

                if ($health === 'has_runs' && ($flow['last_status'] ?? null) === null) {
                    return false;
                }

                if ($health === 'failed' && ($flow['last_status'] ?? '') !== 'failed') {
                    return false;
                }

                if ($health === 'processing' && ! in_array($flow['last_status'] ?? '', ['pending', 'processing'], true)) {
                    return false;
                }

                return true;
            },
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFlow(string $flowId): ?array
    {
        foreach ($this->listFlows() as $flow) {
            if (($flow['id'] ?? '') === $flowId) {
                return $this->enrichDetail($flow);
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function categoryOptions(): array
    {
        $cats = [];
        foreach ($this->listFlows() as $flow) {
            $cat = (string) ($flow['category'] ?? '');
            if ($cat !== '') {
                $cats[$cat] = $this->presentation->categoryLabel($cat);
            }
        }

        asort($cats);

        return $cats;
    }

    /**
     * @return array<string, string>
     */
    public function eventOptions(): array
    {
        $events = [];
        foreach ($this->listFlows() as $flow) {
            $event = (string) ($flow['event_name'] ?? '');
            if ($event !== '') {
                $events[$event] = $this->presentation->eventLabel($event);
            }
        }

        asort($events);

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function flowsFromRules(): array
    {
        try {
            $rules = AutomationRule::query()
                ->with(['actions', 'latestExecution', 'nodes'])
                ->whereNotIn('classification', [
                    AutomationRuleClassification::Sample->value,
                    AutomationRuleClassification::Deprecated->value,
                ])
                ->orderBy('priority')
                ->orderBy('code')
                ->get();
        } catch (Throwable) {
            return [];
        }

        $flows = [];
        foreach ($rules as $rule) {
            if (! $rule instanceof AutomationRule) {
                continue;
            }

            $latest = $rule->latestExecution;
            $stepCount = $rule->actions->count();
            if ($stepCount < 1) {
                $stepCount = $rule->nodes->count();
            }

            $flows[] = [
                'id' => 'rule:'.$rule->code,
                'source' => 'business_hook_rule',
                'category' => 'business_hook',
                'code' => $rule->code,
                'name' => (string) $rule->name,
                'description' => (string) ($rule->description ?? ''),
                'event_name' => (string) $rule->event_name,
                'event_label' => $this->presentation->eventLabel((string) $rule->event_name),
                'step_count' => max(1, $stepCount),
                'enabled' => (bool) $rule->is_enabled,
                'run_mode' => (string) $rule->run_mode,
                'workflow_mode' => (string) $rule->workflow_mode,
                'last_status' => $latest instanceof AutomationExecution ? (string) $latest->status : null,
                'last_run_at' => $latest instanceof AutomationExecution ? optional($latest->started_at)?->toIso8601String() : null,
                'last_error' => $latest instanceof AutomationExecution ? (string) ($latest->error_message ?? '') : null,
                'execution_id' => $latest instanceof AutomationExecution ? (int) $latest->id : null,
                'rule_id' => (int) $rule->id,
            ];
        }

        return $flows;
    }

    /**
     * Registered business events with no AutomationRule yet.
     *
     * @return list<array<string, mixed>>
     */
    private function flowsFromOrphanEvents(): array
    {
        $ruledEvents = [];
        try {
            $ruledEvents = AutomationRule::query()
                ->pluck('event_name')
                ->filter()
                ->unique()
                ->all();
        } catch (Throwable) {
            $ruledEvents = [];
        }

        $ruledLookup = array_fill_keys(array_map('strval', $ruledEvents), true);
        $flows = [];

        try {
            foreach ($this->events->all() as $definition) {
                $eventName = $definition->name;

                if ($eventName === '' || isset($ruledLookup[$eventName])) {
                    continue;
                }

                if (str_starts_with($eventName, 'sample.')) {
                    continue;
                }

                $flows[] = [
                    'id' => 'event:'.$eventName,
                    'source' => 'business_event',
                    'category' => 'registered_event',
                    'code' => $eventName,
                    'name' => $this->presentation->eventLabel($eventName),
                    'description' => __('seo-content-ai::filament.automation.flows.orphan_event_description'),
                    'event_name' => $eventName,
                    'event_label' => $this->presentation->eventLabel($eventName),
                    'step_count' => 1,
                    'enabled' => true,
                    'run_mode' => 'n/a',
                    'workflow_mode' => 'n/a',
                    'last_status' => null,
                    'last_run_at' => null,
                    'last_error' => null,
                    'execution_id' => null,
                    'rule_id' => null,
                ];
            }
        } catch (Throwable) {
            return [];
        }

        return $flows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function flowsFromContentProjectCapabilities(): array
    {
        $byId = [];
        foreach ($this->capabilities->all() as $cap) {
            $id = (string) ($cap['name'] ?? '');
            if ($id !== '') {
                $byId[$id] = $cap;
            }
        }

        $flows = [];
        foreach (self::LIFECYCLE_CAPABILITIES as $capabilityId) {
            $cap = $byId[$capabilityId] ?? null;
            if (! is_array($cap)) {
                continue;
            }

            $command = (string) ($cap['handler'] ?? '');
            $flows[] = [
                'id' => 'capability:'.$capabilityId,
                'source' => 'content_project_capability',
                'category' => 'content_project',
                'code' => $capabilityId,
                'name' => $this->presentation->capabilityLabel($capabilityId),
                'description' => (string) ($cap['description'] ?? ''),
                'event_name' => $capabilityId,
                'event_label' => $this->presentation->capabilityLabel($capabilityId),
                'step_count' => 1,
                'enabled' => true,
                'run_mode' => 'command_bus',
                'workflow_mode' => 'command',
                'last_status' => null,
                'last_run_at' => null,
                'last_error' => null,
                'execution_id' => null,
                'rule_id' => null,
                'handler' => $command,
            ];
        }

        return $flows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function flowsFromContentPipelines(): array
    {
        $definitions = [
            new ArticlePipelineDefinition,
            new RewritePipelineDefinition,
            new ImprovePipelineDefinition,
            new TranslatePipelineDefinition,
            new ProductPipelineDefinition,
        ];

        $flows = [];
        foreach ($definitions as $definition) {
            if (! $definition instanceof PipelineDefinitionInterface) {
                continue;
            }

            $steps = $definition->steps();
            $flows[] = [
                'id' => 'pipeline:'.$definition->key(),
                'source' => 'content_pipeline',
                'category' => 'ai_pipeline',
                'code' => $definition->key(),
                'name' => $definition->name(),
                'description' => __('seo-content-ai::filament.automation.flows.ai_pipeline_description'),
                'event_name' => 'pipeline.'.$definition->key(),
                'event_label' => $definition->name(),
                'step_count' => count($steps),
                'enabled' => true,
                'run_mode' => 'pipeline',
                'workflow_mode' => 'pipeline',
                'last_status' => null,
                'last_run_at' => null,
                'last_error' => null,
                'execution_id' => null,
                'rule_id' => null,
                'pipeline_steps' => $steps,
            ];
        }

        return $flows;
    }

    /**
     * @param  array<string, mixed>  $flow
     * @return array<string, mixed>
     */
    private function enrichDetail(array $flow): array
    {
        $source = (string) ($flow['source'] ?? '');
        $steps = [];

        if ($source === 'business_hook_rule') {
            $ruleId = (int) ($flow['rule_id'] ?? 0);
            $rule = $ruleId > 0
                ? AutomationRule::query()->with(['actions', 'nodes', 'edges'])->find($ruleId)
                : null;

            $steps[] = [
                'type' => 'event',
                'label' => $this->presentation->eventLabel((string) ($flow['event_name'] ?? '')),
                'identifier' => (string) ($flow['event_name'] ?? ''),
                'run_mode' => (string) ($flow['run_mode'] ?? ''),
            ];

            $conditions = is_array($rule?->conditions) ? $rule->conditions : [];
            if ($conditions !== []) {
                $steps[] = [
                    'type' => 'condition',
                    'label' => __('seo-content-ai::filament.automation.flows.step_conditions'),
                    'identifier' => 'conditions',
                    'meta' => $conditions,
                ];
            }

            if ($rule instanceof AutomationRule && $rule->actions->isNotEmpty()) {
                foreach ($rule->actions as $action) {
                    $code = (string) $action->action_code;
                    $steps[] = [
                        'type' => 'action',
                        'label' => $this->presentation->actionLabel($code),
                        'identifier' => $code,
                        'run_mode' => (string) ($flow['run_mode'] ?? ''),
                        'enabled' => (bool) $action->is_enabled,
                        'continue_on_failure' => (bool) $action->continue_on_failure,
                        'delay_seconds' => (int) ($action->delay_seconds ?? 0),
                    ];
                }
            } elseif ($rule instanceof AutomationRule && $rule->nodes->isNotEmpty()) {
                foreach ($rule->nodes as $node) {
                    $steps[] = [
                        'type' => (string) ($node->node_type ?? 'node'),
                        'label' => (string) ($node->label ?? $node->node_key ?? 'node'),
                        'identifier' => (string) ($node->node_key ?? ''),
                        'run_mode' => (string) ($flow['workflow_mode'] ?? 'graph'),
                    ];
                }
            }

            $steps[] = [
                'type' => 'result',
                'label' => __('seo-content-ai::filament.automation.flows.step_result'),
                'identifier' => 'result',
            ];
        } elseif ($source === 'business_event') {
            $steps = [
                [
                    'type' => 'event',
                    'label' => $this->presentation->eventLabel((string) ($flow['event_name'] ?? '')),
                    'identifier' => (string) ($flow['event_name'] ?? ''),
                ],
                [
                    'type' => 'result',
                    'label' => __('seo-content-ai::filament.automation.flows.step_no_rule'),
                    'identifier' => 'unbound',
                ],
            ];
        } elseif ($source === 'content_project_capability') {
            $steps = [
                [
                    'type' => 'command',
                    'label' => (string) ($flow['name'] ?? ''),
                    'identifier' => (string) ($flow['code'] ?? ''),
                    'handler' => (string) ($flow['handler'] ?? ''),
                    'run_mode' => 'command_bus',
                ],
                [
                    'type' => 'result',
                    'label' => __('seo-content-ai::filament.automation.flows.step_result'),
                    'identifier' => 'result',
                ],
            ];
        } elseif ($source === 'content_pipeline') {
            $steps[] = [
                'type' => 'event',
                'label' => __('seo-content-ai::filament.automation.flows.step_pipeline_start'),
                'identifier' => (string) ($flow['code'] ?? ''),
            ];
            foreach (($flow['pipeline_steps'] ?? []) as $pipelineStep) {
                if (! is_array($pipelineStep)) {
                    continue;
                }
                $steps[] = [
                    'type' => 'command',
                    'label' => (string) ($pipelineStep['label'] ?? $pipelineStep['key'] ?? ''),
                    'identifier' => (string) ($pipelineStep['key'] ?? ''),
                    'stage' => (string) ($pipelineStep['stage'] ?? ''),
                    'required' => (bool) ($pipelineStep['required'] ?? false),
                    'run_mode' => 'pipeline',
                ];
            }
            $steps[] = [
                'type' => 'result',
                'label' => __('seo-content-ai::filament.automation.flows.step_result'),
                'identifier' => 'result',
            ];
        }

        $flow['steps'] = $steps;
        $flow['status_label'] = $flow['last_status'] !== null
            ? $this->presentation->statusLabel((string) $flow['last_status'])
            : $this->presentation->statusLabel('never');
        $flow['category_label'] = $this->presentation->categoryLabel((string) ($flow['category'] ?? ''));

        return $flow;
    }

    /**
     * Enrich capability / orphan-event rows with real latest status when available.
     * Rules already carry latestExecution. Never invent executions.
     *
     * @param  list<array<string, mixed>>  $flows
     * @return list<array<string, mixed>>
     */
    private function attachLatestStatuses(array $flows): array
    {
        $capabilityCodes = [];
        $eventNames = [];

        foreach ($flows as $flow) {
            $source = (string) ($flow['source'] ?? '');
            if ($source === 'content_project_capability' && ($flow['last_status'] ?? null) === null) {
                $capabilityCodes[] = (string) ($flow['code'] ?? '');
            }
            if ($source === 'business_event' && ($flow['last_status'] ?? null) === null) {
                $eventNames[] = (string) ($flow['event_name'] ?? '');
            }
        }

        $opsByCommand = $this->latestOperationsByCommand(array_values(array_filter(array_unique($capabilityCodes))));
        $execByEvent = $this->latestExecutionsByEventName(array_values(array_filter(array_unique($eventNames))));

        foreach ($flows as $i => $flow) {
            $source = (string) ($flow['source'] ?? '');

            if ($source === 'content_project_capability') {
                $code = (string) ($flow['code'] ?? '');
                $op = $opsByCommand[$code] ?? null;
                if (is_array($op)) {
                    $flows[$i]['last_status'] = ($op['success'] ?? false) ? 'completed' : 'failed';
                    $flows[$i]['last_run_at'] = $op['finished_at'] ?? $op['started_at'] ?? null;
                    $flows[$i]['last_error'] = (string) ($op['error_message'] ?? $op['result_code'] ?? '');
                }
            }

            if ($source === 'business_event') {
                $event = (string) ($flow['event_name'] ?? '');
                $exec = $execByEvent[$event] ?? null;
                if (is_array($exec)) {
                    $flows[$i]['last_status'] = $exec['status'] ?? null;
                    $flows[$i]['last_run_at'] = $exec['started_at'] ?? null;
                    $flows[$i]['last_error'] = (string) ($exec['error_message'] ?? '');
                    $flows[$i]['execution_id'] = $exec['id'] ?? null;
                }
            }
        }

        return $flows;
    }

    /**
     * @param  list<string>  $commands
     * @return array<string, array<string, mixed>>
     */
    private function latestOperationsByCommand(array $commands): array
    {
        if ($commands === []) {
            return [];
        }

        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_operations')) {
                return [];
            }

            $rows = ContentProjectOperation::query()
                ->whereIn('command', $commands)
                ->orderByDesc('finished_at')
                ->orderByDesc('id')
                ->get(['command', 'success', 'finished_at', 'started_at', 'result_code', 'metadata']);

            $latest = [];
            foreach ($rows as $row) {
                $command = (string) $row->command;
                if ($command === '' || isset($latest[$command])) {
                    continue;
                }
                $meta = is_array($row->metadata) ? $row->metadata : [];
                $latest[$command] = [
                    'success' => (bool) $row->success,
                    'finished_at' => optional($row->finished_at)?->toIso8601String(),
                    'started_at' => optional($row->started_at)?->toIso8601String(),
                    'result_code' => (string) ($row->result_code ?? ''),
                    'error_message' => (string) ($meta['error_message'] ?? $meta['message'] ?? ''),
                ];
            }

            return $latest;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<string>  $eventNames
     * @return array<string, array<string, mixed>>
     */
    private function latestExecutionsByEventName(array $eventNames): array
    {
        if ($eventNames === []) {
            return [];
        }

        try {
            $rows = AutomationExecution::query()
                ->with('businessEvent:id,event_name')
                ->whereHas('businessEvent', static function ($query) use ($eventNames): void {
                    $query->whereIn('event_name', $eventNames);
                })
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->limit(200)
                ->get(['id', 'business_event_id', 'status', 'started_at', 'error_message']);

            $latest = [];
            foreach ($rows as $row) {
                $event = (string) ($row->businessEvent?->event_name ?? '');
                if ($event === '' || isset($latest[$event])) {
                    continue;
                }
                $latest[$event] = [
                    'id' => (int) $row->id,
                    'status' => (string) $row->status,
                    'started_at' => optional($row->started_at)?->toIso8601String(),
                    'error_message' => (string) ($row->error_message ?? ''),
                ];
            }

            return $latest;
        } catch (Throwable) {
            return [];
        }
    }
}
