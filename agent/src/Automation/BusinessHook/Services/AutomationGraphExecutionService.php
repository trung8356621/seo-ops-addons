<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationNodeExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationNodeType;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs\ExecuteAutomationNodeJob;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationNodeExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleEdge;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleNode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationConditionEngine;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationConcurrencyGuard;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphEdgeResolver;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphSnapshot;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphValidator;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationInputMapper;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationLoopGuard;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationRateLimitGuard;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationSnapshotRedactor;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationSubjectLoader;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AutomationGraphExecutionService
{
    public function __construct(
        private readonly AutomationActionRegistry $actionRegistry,
        private readonly AutomationRuleMatcher $matcher,
        private readonly AutomationInputMapper $inputMapper,
        private readonly AutomationConditionEngine $conditionEngine,
        private readonly AutomationGraphEdgeResolver $edgeResolver,
        private readonly AutomationGraphValidator $graphValidator,
        private readonly AutomationSnapshotRedactor $redactor,
        private readonly AutomationLoopGuard $loopGuard,
        private readonly AutomationSubjectLoader $subjectLoader,
        private readonly AutomationRateLimitGuard $rateLimitGuard,
        private readonly AutomationConcurrencyGuard $concurrencyGuard,
        private readonly AutomationVersionService $versionService,
    ) {}

    public function bootstrap(int $executionId): AutomationExecution
    {
        $execution = $this->claimExecution($executionId);
        if ($execution === null) {
            return AutomationExecution::query()->findOrFail($executionId);
        }

        $rule = $execution->rule;
        if (! $rule instanceof AutomationRule) {
            $this->failExecution($execution, BusinessHookErrorCode::RuleValidationFailed->value, 'Missing rule.');

            return $execution->fresh() ?? $execution;
        }

        if (! (bool) $rule->is_enabled || $execution->isCancellationRequested()) {
            $execution->forceFill([
                'status' => AutomationExecutionStatus::Cancelled->value,
                'error_code' => BusinessHookErrorCode::RuleDisabled->value,
                'error_message' => 'Rule disabled or cancellation requested — graph not started.',
                'finished_at' => now(),
                'cancellation_requested_at' => $execution->cancellation_requested_at ?? now(),
            ])->save();

            return $execution->fresh() ?? $execution;
        }

        $snapshot = $this->snapshotForExecution($execution, $rule);
        if ($snapshot->nodes->isEmpty() && ! $rule->isGraphMode()) {
            $this->failExecution($execution, BusinessHookErrorCode::GraphValidationFailed->value, 'No graph snapshot.');

            return $execution->fresh() ?? $execution;
        }

        if ($execution->automation_rule_version_id === null && $snapshot->version !== null) {
            $execution->forceFill(['automation_rule_version_id' => $snapshot->version->id])->save();
        }

        $trigger = $snapshot->nodes->first(
            static fn ($n): bool => (string) $n->node_type === AutomationNodeType::Trigger->value && (bool) $n->is_enabled,
        );

        if ($trigger === null) {
            $this->failExecution($execution, BusinessHookErrorCode::GraphValidationFailed->value, 'Missing trigger node.');

            return $execution->fresh() ?? $execution;
        }

        $nodeExec = $this->createNodeExecution($execution, $trigger);
        $this->dispatchNodeJob($nodeExec);

        return $execution->fresh() ?? $execution;
    }

    private function snapshotForExecution(AutomationExecution $execution, AutomationRule $rule): AutomationGraphSnapshot
    {
        $versionId = $execution->automation_rule_version_id !== null
            ? (int) $execution->automation_rule_version_id
            : null;

        // Graph: chỉ chạy published/archived version — cấm fallback draft live nodes.
        if ($rule->isGraphMode() || $versionId !== null || $rule->published_version_id !== null) {
            $version = $this->versionService->resolveGraphForExecution($rule, $versionId);

            return AutomationGraphSnapshot::fromVersion($rule, $version);
        }

        // Linear legacy (không graph nodes): snapshot virtual/live actions path only.
        return AutomationGraphSnapshot::fromLiveRule($rule);
    }

    public function executeNode(int $nodeExecutionId): void
    {
        $nodeExec = $this->claimNode($nodeExecutionId);
        if ($nodeExec === null) {
            return;
        }

        $execution = $nodeExec->execution()->with(['rule', 'businessEvent'])->first();
        if (! $execution instanceof AutomationExecution) {
            return;
        }

        if ($execution->isCancellationRequested()) {
            $this->finishNode($nodeExec, AutomationNodeExecutionStatus::Cancelled, [], null, 'Execution cancelled.');

            return;
        }

        if ($nodeExec->available_at !== null && $nodeExec->available_at->isFuture()) {
            $nodeExec->forceFill(['status' => AutomationNodeExecutionStatus::Scheduled->value])->save();
            ExecuteAutomationNodeJob::dispatch($nodeExec->id)
                ->delay($nodeExec->available_at)
                ->onQueue($this->queueForNode($nodeExec));

            return;
        }

        $rule = $execution->rule;
        $event = $execution->businessEvent;
        if (! $rule instanceof AutomationRule || ! $event instanceof BusinessEvent) {
            $this->failNode($nodeExec, BusinessHookErrorCode::NodeClaimFailed->value, 'Missing rule or event.');

            return;
        }

        if (! (bool) $rule->is_enabled || $execution->isCancellationRequested()) {
            $this->finishNode(
                $nodeExec,
                AutomationNodeExecutionStatus::Cancelled,
                [],
                BusinessHookErrorCode::RuleDisabled->value,
                'Rule disabled or cancellation requested.',
            );
            $execution->forceFill([
                'status' => AutomationExecutionStatus::Cancelled->value,
                'error_code' => BusinessHookErrorCode::RuleDisabled->value,
                'error_message' => 'Rule disabled — node side effects blocked.',
                'finished_at' => now(),
                'cancellation_requested_at' => $execution->cancellation_requested_at ?? now(),
            ])->save();

            return;
        }

        $snapshot = $this->snapshotForExecution($execution, $rule);
        $ruleNode = $snapshot->findNode($nodeExec->node_key);
        if ($ruleNode === null || ! (bool) $ruleNode->is_enabled) {
            $this->finishNode($nodeExec, AutomationNodeExecutionStatus::Skipped, [], null, 'Node disabled.');
            $this->finalizeExecutionIfDone($execution->id);

            return;
        }

        if ((string) $ruleNode->node_type === AutomationNodeType::Delay->value) {
            if ($nodeExec->available_at === null) {
                $this->armDelayNode($ruleNode, $nodeExec);

                return;
            }
        }

        $visitCount = $this->incrementVisitCount($execution, (string) $ruleNode->node_key);
        $maxVisits = $this->graphValidator->maxCycleVisits($ruleNode);
        if ($visitCount > $maxVisits) {
            $this->failNode($nodeExec, BusinessHookErrorCode::GraphCycleDetected->value, 'Max cycle visits exceeded.');

            return;
        }

        $loaded = $this->subjectLoader->load($event);
        if ($loaded['error_code'] !== null && $event->subject_id !== null) {
            $this->failExecution($execution, (string) $loaded['error_code'], (string) ($loaded['error_message'] ?? 'Subject unavailable.'));
            $this->failNode($nodeExec, (string) $loaded['error_code'], (string) ($loaded['error_message'] ?? 'Subject unavailable.'));

            return;
        }

        $subject = $loaded['model'];
        $subjectData = $subject instanceof Model ? $this->subjectArray($subject) : [];
        $sources = $this->matcher->buildSources($event, $subjectData);
        $sources['previous'] = $this->collectPreviousOutputs($execution);

        $result = $this->runNodeType($ruleNode, $execution, $event, $rule, $subject, $subjectData, $sources, $nodeExec);

        if (($result->output['rate_limited'] ?? false) === true) {
            $nodeExec->forceFill(['status' => AutomationNodeExecutionStatus::Scheduled->value])->save();

            return;
        }

        if ($result->success) {
            $this->finishNode(
                $nodeExec,
                AutomationNodeExecutionStatus::Completed,
                $result->output,
                null,
                $result->message,
                $nodeExec->selected_branch,
            );
            $this->dispatchFollowUpEvents($result, $event, $rule);
            $this->scheduleNextNodes($execution, $snapshot, $ruleNode, true, $nodeExec->selected_branch);
        } else {
            $retried = $this->maybeRetryNode($nodeExec, $ruleNode, $result);
            if ($retried) {
                return;
            }

            $this->finishNode(
                $nodeExec,
                AutomationNodeExecutionStatus::Failed,
                $result->output,
                $result->errorCode,
                $result->message,
            );

            $failureEdges = $this->edgeResolver->resolve($snapshot->edges, (string) $ruleNode->node_key, false);
            if ($failureEdges !== []) {
                $this->scheduleEdges($execution, $snapshot, $failureEdges);
            } elseif ($rule->stop_on_failure) {
                $this->failExecution(
                    $execution,
                    $result->errorCode ?? BusinessHookErrorCode::RuleValidationFailed->value,
                    $result->message,
                );
            } else {
                $this->scheduleNextNodes($execution, $snapshot, $ruleNode, false, null);
            }
        }

        $this->finalizeExecutionIfDone($execution->id);
    }

    public function cancelExecution(int $executionId): AutomationExecution
    {
        $execution = AutomationExecution::query()->findOrFail($executionId);

        if (in_array($execution->status, [
            AutomationExecutionStatus::Completed->value,
            AutomationExecutionStatus::Failed->value,
            AutomationExecutionStatus::Cancelled->value,
        ], true)) {
            return $execution;
        }

        if (in_array($execution->status, [
            AutomationExecutionStatus::Pending->value,
        ], true)) {
            $execution->forceFill([
                'status' => AutomationExecutionStatus::Cancelled->value,
                'finished_at' => now(),
                'error_code' => BusinessHookErrorCode::ExecutionCancelled->value,
            ])->save();
            AutomationNodeExecution::query()
                ->where('automation_execution_id', $execution->id)
                ->whereIn('status', [
                    AutomationNodeExecutionStatus::Pending->value,
                    AutomationNodeExecutionStatus::Scheduled->value,
                ])
                ->update(['status' => AutomationNodeExecutionStatus::Cancelled->value]);

            return $execution->fresh() ?? $execution;
        }

        $execution->forceFill(['cancellation_requested_at' => now()])->save();

        return $execution->fresh() ?? $execution;
    }

    public function retryNode(int $nodeExecutionId): void
    {
        $nodeExec = AutomationNodeExecution::query()->findOrFail($nodeExecutionId);
        if ($nodeExec->status !== AutomationNodeExecutionStatus::Failed->value) {
            return;
        }

        $execution = $nodeExec->execution;
        if (! $execution instanceof AutomationExecution) {
            return;
        }

        $attempt = (int) $nodeExec->attempt + 1;
        $snapshot = $this->snapshotForExecution($execution, $execution->rule);
        $ruleNode = $snapshot->findNode($nodeExec->node_key);
        if ($ruleNode === null) {
            return;
        }

        $fresh = $this->createNodeExecution($execution, $ruleNode, $attempt);
        $execution->forceFill(['status' => AutomationExecutionStatus::Processing->value])->save();
        $this->dispatchNodeJob($fresh);
    }

    public function retryExecution(int $executionId): AutomationExecution
    {
        $execution = AutomationExecution::query()->with('rule.nodes')->findOrFail($executionId);
        $failedNodes = AutomationNodeExecution::query()
            ->where('automation_execution_id', $execution->id)
            ->where('status', AutomationNodeExecutionStatus::Failed->value)
            ->orderBy('id')
            ->get();

        if ($failedNodes->isEmpty()) {
            return $execution;
        }

        $execution->forceFill([
            'status' => AutomationExecutionStatus::Processing->value,
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $first = $failedNodes->first();
        if ($first instanceof AutomationNodeExecution) {
            $this->retryNode((int) $first->id);
        }

        return $execution->fresh() ?? $execution;
    }

    /**
     * @param  array<string, mixed>  $subjectData
     * @param  array<string, mixed>  $sources
     */
    private function runNodeType(
        object $ruleNode,
        AutomationExecution $execution,
        BusinessEvent $event,
        AutomationRule $rule,
        ?Model $subject,
        array $subjectData,
        array $sources,
        AutomationNodeExecution $nodeExec,
    ): AutomationActionResult {
        $type = AutomationNodeType::tryFrom($ruleNode->node_type);

        return match ($type) {
            AutomationNodeType::Trigger => AutomationActionResult::success(['trigger' => $ruleNode->node_key]),
            AutomationNodeType::End => AutomationActionResult::success(['end' => $ruleNode->node_key]),
            AutomationNodeType::Condition => $this->runConditionNode($ruleNode, $sources, $nodeExec),
            AutomationNodeType::Delay => AutomationActionResult::success(['delay_elapsed' => true]),
            AutomationNodeType::DispatchEvent => $this->runDispatchNode($ruleNode, $execution, $event, $rule, $subject, $subjectData, $sources, $nodeExec),
            AutomationNodeType::Action => $this->runActionNode($ruleNode, $execution, $event, $rule, $subject, $subjectData, $sources, $nodeExec),
            default => AutomationActionResult::failure(BusinessHookErrorCode::RuleValidationFailed->value, 'Unknown node type.'),
        };
    }

    /**
     * @param  array<string, mixed>  $sources
     */
    private function runConditionNode(
        object $ruleNode,
        array $sources,
        AutomationNodeExecution $nodeExec,
    ): AutomationActionResult {
        $conditions = is_array($ruleNode->config['conditions'] ?? null) ? $ruleNode->config['conditions'] : [];
        $matched = $this->conditionEngine->matches($conditions, $sources);
        $branch = $matched ? 'true' : 'false';
        $nodeExec->forceFill(['selected_branch' => $branch])->save();

        return AutomationActionResult::success(['matched' => $matched, 'branch' => $branch]);
    }

    private function armDelayNode(object $ruleNode, AutomationNodeExecution $nodeExec): void
    {
        $seconds = max(1, (int) ($ruleNode->config['seconds'] ?? $ruleNode->settings['seconds'] ?? 0));
        $availableAt = now()->addSeconds($seconds);
        $nodeExec->forceFill([
            'status' => AutomationNodeExecutionStatus::Scheduled->value,
            'available_at' => $availableAt,
            'output_snapshot' => ['delayed_seconds' => $seconds],
        ])->save();

        ExecuteAutomationNodeJob::dispatch($nodeExec->id)
            ->delay($availableAt)
            ->onQueue('automation');
    }

    /**
     * @param  array<string, mixed>  $subjectData
     * @param  array<string, mixed>  $sources
     */
    private function runActionNode(
        object $ruleNode,
        AutomationExecution $execution,
        BusinessEvent $event,
        AutomationRule $rule,
        ?Model $subject,
        array $subjectData,
        array $sources,
        AutomationNodeExecution $nodeExec,
    ): AutomationActionResult {
        $actionCode = trim((string) ($ruleNode->action_code ?? ''));
        if ($actionCode === '' || ! $this->actionRegistry->has($actionCode)) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::ActionNotRegistered->value,
                "Action [{$actionCode}] not registered.",
            );
        }

        $rate = $this->rateLimitGuard->check($actionCode, $event->site_id);
        if (! $rate['allowed']) {
            $nodeExec->forceFill([
                'status' => AutomationNodeExecutionStatus::Scheduled->value,
                'available_at' => now()->addSeconds($rate['retry_after_seconds']),
                'error_code' => BusinessHookErrorCode::RateLimited->value,
            ])->save();
            ExecuteAutomationNodeJob::dispatch($nodeExec->id)
                ->delay(now()->addSeconds($rate['retry_after_seconds']))
                ->onQueue($this->actionRegistry->get($actionCode)->defaultQueue);

            return AutomationActionResult::success(['rate_limited' => true]);
        }

        $input = $this->inputMapper->map($ruleNode->input_mapping ?? [], $sources);
        $settings = $ruleNode->settings ?? [];
        $inputErrors = $this->actionRegistry->validateInput($actionCode, $input);
        if ($inputErrors !== []) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::MissingRequiredInput->value,
                implode(' ', $inputErrors),
            );
        }

        $nodeExec->forceFill([
            'input_snapshot' => $this->redactor->redact($input),
            'heartbeat_at' => now(),
        ])->save();

        try {
            $handler = $this->actionRegistry->resolveHandler($actionCode);
            $context = new AutomationActionContext(
                businessEvent: $event,
                rule: $rule,
                execution: $execution,
                subject: $subject,
                subjectData: $subjectData,
                siteId: $event->site_id,
                projectId: $event->project_id,
                actorId: isset(($event->context ?? [])['actor_id']) ? (int) $event->context['actor_id'] : null,
                correlationId: isset(($event->context ?? [])['correlation_id'])
                    ? (string) $event->context['correlation_id']
                    : null,
                automationDepth: (int) (($event->context ?? [])['automation_depth'] ?? 0),
                previousOutputs: $sources['previous'] ?? [],
                dryRun: false,
                nodeExecutionId: (int) $nodeExec->id,
                nodeIdempotencyKey: $nodeExec->idempotency_key,
                nodeKey: $nodeExec->node_key,
            );

            return $handler->handle($context, $input, $settings);
        } catch (AutomationException $e) {
            return AutomationActionResult::failure($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            return AutomationActionResult::failure('AUTOMATION_ACTION_EXCEPTION', $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $subjectData
     * @param  array<string, mixed>  $sources
     */
    private function runDispatchNode(
        object $ruleNode,
        AutomationExecution $execution,
        BusinessEvent $event,
        AutomationRule $rule,
        ?Model $subject,
        array $subjectData,
        array $sources,
        AutomationNodeExecution $nodeExec,
    ): AutomationActionResult {
        $virtual = (object) [
            'id' => $ruleNode->id ?? null,
            'node_key' => $ruleNode->node_key,
            'node_type' => $ruleNode->node_type,
            'action_code' => 'automation.dispatch_event',
            'config' => $ruleNode->config ?? null,
            'input_mapping' => $ruleNode->input_mapping ?? null,
            'settings' => $ruleNode->settings ?? null,
            'is_enabled' => $ruleNode->is_enabled ?? true,
        ];

        return $this->runActionNode($virtual, $execution, $event, $rule, $subject, $subjectData, $sources, $nodeExec);
    }

    private function scheduleNextNodes(
        AutomationExecution $execution,
        AutomationGraphSnapshot $snapshot,
        object $fromNode,
        bool $success,
        ?string $conditionBranch,
    ): void {
        if ((string) $fromNode->node_type === AutomationNodeType::Delay->value) {
            $edges = $snapshot->edges->filter(
                static fn ($e): bool => (string) $e->from_node_key === (string) $fromNode->node_key,
            );

            $this->scheduleEdges($execution, $snapshot, $edges->all());

            return;
        }

        if ((string) $fromNode->node_type === AutomationNodeType::Condition->value) {
            $branch = $conditionBranch ?? 'false';
            $edges = $this->edgeResolver->resolve($snapshot->edges, (string) $fromNode->node_key, true, $branch);
            $this->scheduleEdges($execution, $snapshot, $edges);

            return;
        }

        $edges = $this->edgeResolver->resolve($snapshot->edges, (string) $fromNode->node_key, $success);
        $this->scheduleEdges($execution, $snapshot, $edges);
    }

    /**
     * @param  list<object>  $edges
     */
    private function scheduleEdges(AutomationExecution $execution, AutomationGraphSnapshot $snapshot, array $edges): void
    {
        foreach ($edges as $edge) {
            $target = $snapshot->findNode((string) $edge->to_node_key);
            if ($target === null || ! (bool) $target->is_enabled) {
                continue;
            }

            $existing = AutomationNodeExecution::query()
                ->where('automation_execution_id', $execution->id)
                ->where('node_key', $target->node_key)
                ->where('status', AutomationNodeExecutionStatus::Completed->value)
                ->exists();
            if ($existing) {
                continue;
            }

            $pending = AutomationNodeExecution::query()
                ->where('automation_execution_id', $execution->id)
                ->where('node_key', $target->node_key)
                ->whereIn('status', [
                    AutomationNodeExecutionStatus::Pending->value,
                    AutomationNodeExecutionStatus::Scheduled->value,
                    AutomationNodeExecutionStatus::Processing->value,
                ])
                ->first();

            if ($pending instanceof AutomationNodeExecution) {
                continue;
            }

            $nodeExec = $this->createNodeExecution($execution, $target);
            $this->dispatchNodeJob($nodeExec);
        }
    }

    private function createNodeExecution(
        AutomationExecution $execution,
        object $ruleNode,
        int $attempt = 1,
    ): AutomationNodeExecution {
        $idempotencyKey = hash('sha256', $execution->id.'|'.$ruleNode->node_key.'|'.$attempt);

        return AutomationNodeExecution::query()->create([
            'automation_execution_id' => $execution->id,
            'automation_rule_node_id' => isset($ruleNode->id) && $ruleNode instanceof AutomationRuleNode
                ? (int) $ruleNode->id
                : null,
            'node_key' => (string) $ruleNode->node_key,
            'node_type' => (string) $ruleNode->node_type,
            'status' => AutomationNodeExecutionStatus::Pending->value,
            'attempt' => $attempt,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    private function dispatchNodeJob(AutomationNodeExecution $nodeExec): void
    {
        ExecuteAutomationNodeJob::dispatch($nodeExec->id)
            ->onQueue($this->queueForNode($nodeExec));
    }

    private function queueForNode(AutomationNodeExecution $nodeExec): string
    {
        if ($nodeExec->node_type === AutomationNodeType::Action->value) {
            $code = '';
            if ($nodeExec->automation_rule_node_id) {
                $ruleNode = AutomationRuleNode::query()->find($nodeExec->automation_rule_node_id);
                $code = trim((string) ($ruleNode?->action_code ?? ''));
            }
            if ($code === '' && $nodeExec->execution) {
                $snapshot = $this->snapshotForExecution($nodeExec->execution, $nodeExec->execution->rule);
                $code = trim((string) ($snapshot->findNode($nodeExec->node_key)?->action_code ?? ''));
            }
            if ($code !== '' && $this->actionRegistry->has($code)) {
                return $this->actionRegistry->get($code)->defaultQueue;
            }
        }

        return 'automation';
    }

    private function claimExecution(int $executionId): ?AutomationExecution
    {
        return \App\Support\Automation\AutomationConnection::db()->transaction(function () use ($executionId): ?AutomationExecution {
            /** @var AutomationExecution|null $execution */
            $execution = AutomationExecution::query()->whereKey($executionId)->lockForUpdate()->first();
            if (! $execution instanceof AutomationExecution) {
                return null;
            }

            if (in_array($execution->status, [
                AutomationExecutionStatus::Completed->value,
                AutomationExecutionStatus::Partial->value,
                AutomationExecutionStatus::Cancelled->value,
                AutomationExecutionStatus::Skipped->value,
            ], true)) {
                return null;
            }

            if ($execution->status === AutomationExecutionStatus::Processing->value) {
                $started = $execution->started_at;
                if ($started !== null && $started->diffInSeconds(now()) < 900) {
                    return null;
                }
            }

            $execution->forceFill([
                'status' => AutomationExecutionStatus::Processing->value,
                'started_at' => $execution->started_at ?? now(),
                'heartbeat_at' => now(),
            ])->save();

            return $execution;
        });
    }

    private function claimNode(int $nodeExecutionId): ?AutomationNodeExecution
    {
        return \App\Support\Automation\AutomationConnection::db()->transaction(function () use ($nodeExecutionId): ?AutomationNodeExecution {
            /** @var AutomationNodeExecution|null $nodeExec */
            $nodeExec = AutomationNodeExecution::query()->whereKey($nodeExecutionId)->lockForUpdate()->first();
            if (! $nodeExec instanceof AutomationNodeExecution) {
                return null;
            }

            if (in_array($nodeExec->status, [
                AutomationNodeExecutionStatus::Completed->value,
                AutomationNodeExecutionStatus::Failed->value,
                AutomationNodeExecutionStatus::Cancelled->value,
                AutomationNodeExecutionStatus::Skipped->value,
            ], true)) {
                return null;
            }

            if ($nodeExec->status === AutomationNodeExecutionStatus::Processing->value) {
                return null;
            }

            if ($nodeExec->available_at !== null && $nodeExec->available_at->isFuture()) {
                return null;
            }

            $nodeExec->forceFill([
                'status' => AutomationNodeExecutionStatus::Processing->value,
                'started_at' => $nodeExec->started_at ?? now(),
                'heartbeat_at' => now(),
            ])->save();

            return $nodeExec;
        });
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function finishNode(
        AutomationNodeExecution $nodeExec,
        AutomationNodeExecutionStatus $status,
        array $output,
        ?string $errorCode,
        ?string $message,
        ?string $selectedBranch = null,
    ): void {
        $nodeExec->forceFill([
            'status' => $status->value,
            'output_snapshot' => $this->redactor->redact($output),
            'error_code' => $errorCode,
            'error_message' => $message !== null ? $this->redactor->redactMessage($message) : null,
            'finished_at' => now(),
            'selected_branch' => $selectedBranch ?? $nodeExec->selected_branch,
        ])->save();
    }

    private function failNode(AutomationNodeExecution $nodeExec, string $code, string $message): void
    {
        $this->finishNode($nodeExec, AutomationNodeExecutionStatus::Failed, [], $code, $message);
    }

    private function failExecution(AutomationExecution $execution, string $code, string $message): void
    {
        $execution->forceFill([
            'status' => AutomationExecutionStatus::Failed->value,
            'error_code' => $code,
            'error_message' => $this->redactor->redactMessage($message),
            'finished_at' => now(),
        ])->save();
    }

    private function maybeRetryNode(
        AutomationNodeExecution $nodeExec,
        object $ruleNode,
        AutomationActionResult $result,
    ): bool {
        $retry = is_array($ruleNode->config['retry'] ?? null) ? $ruleNode->config['retry'] : [];
        $maxAttempts = max(1, (int) ($retry['max_attempts'] ?? 1));
        $backoffs = is_array($retry['backoff_seconds'] ?? null) ? $retry['backoff_seconds'] : [];

        if ((int) $nodeExec->attempt >= $maxAttempts) {
            return false;
        }

        $index = min(count($backoffs) - 1, (int) $nodeExec->attempt - 1);
        $delay = $index >= 0 ? max(1, (int) ($backoffs[$index] ?? 60)) : 60;

        $this->finishNode(
            $nodeExec,
            AutomationNodeExecutionStatus::Failed,
            $result->output,
            $result->errorCode,
            $result->message.' (retry scheduled)',
        );

        $fresh = $this->createNodeExecution(
            $nodeExec->execution()->first() ?? AutomationExecution::query()->findOrFail($nodeExec->automation_execution_id),
            $ruleNode,
            (int) $nodeExec->attempt + 1,
        );
        $fresh->forceFill([
            'status' => AutomationNodeExecutionStatus::Scheduled->value,
            'available_at' => now()->addSeconds($delay),
        ])->save();

        ExecuteAutomationNodeJob::dispatch($fresh->id)
            ->delay(now()->addSeconds($delay))
            ->onQueue($this->queueForNode($fresh));

        return true;
    }

    private function finalizeExecutionIfDone(int $executionId): void
    {
        $execution = AutomationExecution::query()->with(['rule.nodes', 'nodeExecutions'])->find($executionId);
        if (! $execution instanceof AutomationExecution) {
            return;
        }

        $active = $execution->nodeExecutions->contains(static fn (AutomationNodeExecution $n): bool => in_array($n->status, [
            AutomationNodeExecutionStatus::Pending->value,
            AutomationNodeExecutionStatus::Scheduled->value,
            AutomationNodeExecutionStatus::Processing->value,
            AutomationNodeExecutionStatus::Waiting->value,
        ], true));

        if ($active) {
            return;
        }

        $failed = $execution->nodeExecutions->contains(
            static fn (AutomationNodeExecution $n): bool => $n->status === AutomationNodeExecutionStatus::Failed->value,
        );

        $status = $failed
            ? ($execution->rule?->stop_on_failure ? AutomationExecutionStatus::Failed : AutomationExecutionStatus::Partial)
            : AutomationExecutionStatus::Completed;

        $execution->forceFill([
            'status' => $status->value,
            'finished_at' => now(),
        ])->save();
    }

    private function incrementVisitCount(AutomationExecution $execution, string $nodeKey): int
    {
        $context = $execution->context ?? [];
        $visits = is_array($context['node_visits'] ?? null) ? $context['node_visits'] : [];
        $visits[$nodeKey] = (int) ($visits[$nodeKey] ?? 0) + 1;
        $context['node_visits'] = $visits;
        $execution->forceFill(['context' => $context])->save();

        return (int) $visits[$nodeKey];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectPreviousOutputs(AutomationExecution $execution): array
    {
        $outputs = [];
        foreach ($execution->nodeExecutions as $nodeExec) {
            if ($nodeExec->status === AutomationNodeExecutionStatus::Completed->value) {
                $outputs[$nodeExec->node_key] = is_array($nodeExec->output_snapshot) ? $nodeExec->output_snapshot : [];
            }
        }

        return $outputs;
    }

    /**
     * @return array<string, mixed>
     */
    private function subjectArray(Model $subject): array
    {
        return array_merge(
            ['id' => (int) $subject->getKey()],
            $subject->only(array_intersect(
                ['site_id', 'project_id', 'article_id', 'post_type', 'status', 'title'],
                array_keys($subject->getAttributes()),
            )),
        );
    }

    private function dispatchFollowUpEvents(
        AutomationActionResult $result,
        BusinessEvent $parentEvent,
        AutomationRule $rule,
    ): void {
        if ($result->dispatchEvents === []) {
            return;
        }

        $dispatcher = app(BusinessEventDispatcher::class);

        foreach ($result->dispatchEvents as $followUp) {
            $eventName = (string) ($followUp['event_name'] ?? '');
            if ($eventName === '') {
                continue;
            }

            try {
                $childContext = $this->loopGuard->childContext(
                    array_merge($parentEvent->context ?? [], ['event_uuid' => $parentEvent->event_uuid]),
                    $eventName,
                    (int) $rule->id,
                );

                $dispatcher->dispatch(
                    eventName: $eventName,
                    subject: $parentEvent->subject_type,
                    payload: is_array($followUp['payload'] ?? null) ? $followUp['payload'] : ($parentEvent->payload ?? []),
                    context: array_merge($childContext, is_array($followUp['context'] ?? null) ? $followUp['context'] : []),
                );
            } catch (\Throwable $e) {
                Log::warning('automation.graph.follow_up_failed', [
                    'parent_event' => $parentEvent->event_uuid,
                    'event_name' => $eventName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
