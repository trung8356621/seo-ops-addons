<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationActionExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationInputMapper;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationLoopGuard;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationSnapshotRedactor;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationSubjectLoader;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AutomationExecutionService
{
    private const STALE_PROCESSING_SECONDS = 900;

    public function __construct(
        private readonly AutomationActionRegistry $actionRegistry,
        private readonly AutomationRuleMatcher $matcher,
        private readonly AutomationInputMapper $inputMapper,
        private readonly AutomationSnapshotRedactor $redactor,
        private readonly AutomationLoopGuard $loopGuard,
        private readonly AutomationSubjectLoader $subjectLoader,
        private readonly AutomationVersionService $versionService,
    ) {}

    public function createPendingExecution(BusinessEvent $event, AutomationRule $rule): ?AutomationExecution
    {
        $versionId = $rule->published_version_id ? (int) $rule->published_version_id : null;
        $versionNumber = (int) $rule->version;

        // Graph / versioned nodes: cấm auto-publish trên execution path.
        // Chưa publish → skip (draft không bao giờ chạy). Publish chỉ qua UI/CLI.
        if ($versionId === null && ($rule->isGraphMode() || $rule->nodes()->exists())) {
            Log::warning('automation.execution.skipped_unpublished_graph', [
                'rule_id' => $rule->id,
                'rule_code' => $rule->code,
                'workflow_mode' => $rule->workflow_mode,
            ]);

            return null;
        }

        $idempotencyKey = hash('sha256', $event->event_uuid.'|'.$rule->id.'|'.$versionNumber.'|'.($versionId ?? 0));

        $existing = AutomationExecution::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof AutomationExecution) {
            return null;
        }

        try {
            return AutomationExecution::query()->create([
                'execution_uuid' => (string) Str::uuid(),
                'business_event_id' => $event->id,
                'automation_rule_id' => $rule->id,
                'automation_rule_version_id' => $versionId,
                'rule_version' => $versionNumber,
                'status' => AutomationExecutionStatus::Pending->value,
                'trigger_type' => $rule->trigger_type ?: 'event',
                'action_code' => null,
                'attempt' => 1,
                'idempotency_key' => $idempotencyKey,
                'context' => [
                    'event_uuid' => $event->event_uuid,
                    'rule_code' => $rule->code,
                    'rule_version' => $versionNumber,
                    'automation_rule_version_id' => $versionId,
                    'next_position' => 0,
                    'delayed_positions' => [],
                    'previous_outputs' => [],
                    'idempotency_key' => $idempotencyKey,
                ],
            ]);
        } catch (\Throwable $e) {
            $again = AutomationExecution::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            return $again instanceof AutomationExecution ? null : throw $e;
        }
    }

    public function run(int $executionId, bool $dryRun = false): AutomationExecution
    {
        $claimed = $this->claim($executionId);
        if ($claimed === null) {
            return AutomationExecution::query()->findOrFail($executionId);
        }

        $execution = $claimed;
        $event = $execution->businessEvent;

        // Option A: manual single-action — immutable snapshot in context, no event rule required.
        if (($execution->context['manual_action'] ?? null) !== null
            || (string) ($execution->trigger_type ?? '') === 'manual'
        ) {
            return $this->runManualSingleAction($execution, $event, $dryRun);
        }

        $rule = $execution->rule()->with('actions')->first();

        if (! $event instanceof BusinessEvent || ! $rule instanceof AutomationRule) {
            $this->failExecution($execution, BusinessHookErrorCode::ExecutionClaimFailed->value, 'Missing event or rule.');

            return $execution->fresh() ?? $execution;
        }

        // Disable after enqueue must still block WordPress / external side effects.
        if (! (bool) $rule->is_enabled || $execution->isCancellationRequested()) {
            $execution->forceFill([
                'status' => AutomationExecutionStatus::Cancelled->value,
                'error_code' => BusinessHookErrorCode::RuleDisabled->value,
                'error_message' => 'Rule disabled or cancellation requested — no side effects.',
                'finished_at' => now(),
                'cancellation_requested_at' => $execution->cancellation_requested_at ?? now(),
            ])->save();

            Log::info('automation.execution.cancelled_rule_disabled', [
                'automation_execution_id' => $execution->id,
                'rule_id' => $rule->id,
                'rule_code' => $rule->code,
            ]);

            return $execution->fresh() ?? $execution;
        }

        try {
            $this->loopGuard->assertAllowed(
                array_merge($event->context ?? [], $execution->context ?? []),
                $event->event_name,
                (int) $rule->id,
            );
        } catch (AutomationException $e) {
            $this->failExecution($execution, $e->errorCode, $e->getMessage());

            return $execution->fresh() ?? $execution;
        }

        $loaded = $this->subjectLoader->load($event);
        if ($loaded['error_code'] !== null && $event->subject_id !== null) {
            // Subject was declared but missing/deleted — fail execution clearly (no silent substitute).
            $this->failExecution(
                $execution,
                (string) $loaded['error_code'],
                (string) ($loaded['error_message'] ?? 'Subject unavailable.'),
            );

            return $execution->fresh() ?? $execution;
        }

        $subject = $loaded['model'];
        if ($subject instanceof Model) {
            $sources = $this->matcher->buildSources($event, $this->subjectArray($subject));
            $subjectData = $sources['subject'];
        } else {
            $sources = $this->matcher->buildSources($event);
            $subjectData = $sources['subject'];
        }

        $contextPayload = $execution->context ?? [];
        $nextPosition = (int) ($contextPayload['next_position'] ?? 0);
        $delayedPositions = is_array($contextPayload['delayed_positions'] ?? null)
            ? $contextPayload['delayed_positions']
            : [];
        /** @var array<string, mixed> $previousOutputs */
        $previousOutputs = is_array($contextPayload['previous_outputs'] ?? null)
            ? $contextPayload['previous_outputs']
            : [];

        $actions = $rule->actions->sortBy('position')->values();
        $hadFailure = false;
        $stopped = false;

        foreach ($actions as $action) {
            if (! $action instanceof AutomationRuleAction) {
                continue;
            }

            if ((int) $action->position < $nextPosition) {
                continue;
            }

            // Queue retry safety: skip side effects đã completed.
            $existingAction = AutomationActionExecution::query()
                ->where('automation_execution_id', $execution->id)
                ->where('position', (int) $action->position)
                ->first();
            if ($existingAction instanceof AutomationActionExecution
                && $existingAction->status === AutomationActionExecutionStatus::Completed->value
            ) {
                $previousOutputs[(string) $action->position] = is_array($existingAction->output_snapshot)
                    ? $existingAction->output_snapshot
                    : [];
                $nextPosition = (int) $action->position + 1;
                continue;
            }

            if (! $action->is_enabled) {
                $this->recordActionStatus(
                    $execution,
                    $action,
                    AutomationActionExecutionStatus::Skipped,
                    [],
                    [],
                    null,
                    'Action disabled.',
                );
                $nextPosition = (int) $action->position + 1;
                continue;
            }

            $delaySeconds = max(0, (int) $action->delay_seconds);
            $settingsForDelay = is_array($action->settings) ? $action->settings : [];
            $executionContext = is_array($execution->context) ? $execution->context : [];
            $eventPayload = is_array($event->payload ?? null) ? $event->payload : [];
            $delayAlreadyApplied = (bool) ($executionContext['product_review_delay_applied']
                ?? $eventPayload['product_review_delay_applied']
                ?? false);

            // max_delay_time chỉ cho child publish — KHÔNG delay schedule_generated / queue_pending.
            $actionCode = (string) $action->action_code;
            $isProductReviewScheduler = in_array($actionCode, [
                'article.product_reviews.schedule_generated',
                'article.product_reviews.queue_pending',
            ], true);

            if (! $isProductReviewScheduler
                && ! $delayAlreadyApplied
                && ! in_array((int) $action->position, $delayedPositions, true)
            ) {
                $maxDelayMinutes = (int) ($settingsForDelay['max_delay_time']
                    ?? $settingsForDelay['delay_max_after_minutes']
                    ?? 0);
                if ($maxDelayMinutes > 0) {
                    $minSeconds = 60;
                    $maxSeconds = max($minSeconds, $maxDelayMinutes * 60);
                    $delaySeconds = random_int($minSeconds, $maxSeconds);
                }
            } elseif ($delayAlreadyApplied || $isProductReviewScheduler) {
                // Scheduler actions chạy ngay; delay nằm ở DispatchScheduledProductReviewPublishJob.
                if ($isProductReviewScheduler) {
                    $delaySeconds = max(0, (int) $action->delay_seconds);
                } else {
                    $delaySeconds = 0;
                }
            }
            if ($delaySeconds > 0 && ! in_array((int) $action->position, $delayedPositions, true)) {
                $delayedPositions[] = (int) $action->position;
                $this->persistProgress($execution, $nextPosition, $delayedPositions, $previousOutputs);
                $execution->forceFill([
                    'status' => AutomationExecutionStatus::Pending->value,
                ])->save();
                ExecuteAutomationRuleJob::dispatch($execution->id)->delay(now()->addSeconds($delaySeconds));

                return $execution->fresh() ?? $execution;
            }

            $sources['previous'] = $previousOutputs;
            $input = $this->inputMapper->map($action->input_mapping ?? [], $sources);
            $settings = $action->settings ?? [];

            if ($dryRun) {
                $this->recordActionStatus(
                    $execution,
                    $action,
                    AutomationActionExecutionStatus::Completed,
                    $input,
                    ['dry_run' => true],
                    null,
                    'Dry run — action not executed.',
                );
                $nextPosition = (int) $action->position + 1;
                continue;
            }

            $actionExec = $this->startActionExecution($execution, $action, $input);

            try {
                if (! $this->actionRegistry->has($action->action_code)) {
                    throw new AutomationException(
                        BusinessHookErrorCode::ActionNotRegistered->value,
                        "Action [{$action->action_code}] is not registered.",
                    );
                }

                $inputErrors = $this->actionRegistry->validateInput($action->action_code, $input);
                if ($inputErrors !== []) {
                    throw new AutomationException(
                        BusinessHookErrorCode::MissingRequiredInput->value,
                        implode(' ', $inputErrors),
                    );
                }

                $handler = $this->actionRegistry->resolveHandler($action->action_code);
                $actionContext = new AutomationActionContext(
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
                    previousOutputs: $previousOutputs,
                    dryRun: false,
                );

                $result = $handler->handle($actionContext, $input, $settings);
            } catch (AutomationException $e) {
                $result = AutomationActionResult::failure($e->errorCode, $e->getMessage());
            } catch (\Throwable $e) {
                $result = AutomationActionResult::failure('AUTOMATION_ACTION_EXCEPTION', $e->getMessage());
            }

            $this->finishActionExecution($actionExec, $result);

            if ($result->success) {
                $previousOutputs[(string) $action->position] = $result->output;
                $this->dispatchFollowUpEvents($result, $event, $rule);
            } else {
                $hadFailure = true;
                $stop = $rule->stop_on_failure && ! $action->continue_on_failure;
                if ($stop) {
                    $stopped = true;
                    $nextPosition = (int) $action->position + 1;
                    $this->persistProgress($execution, $nextPosition, $delayedPositions, $previousOutputs);
                    break;
                }
            }

            $nextPosition = (int) $action->position + 1;
            $this->persistProgress($execution, $nextPosition, $delayedPositions, $previousOutputs);
        }

        $status = AutomationExecutionStatus::Completed;
        if ($hadFailure && $stopped) {
            $status = AutomationExecutionStatus::Failed;
        } elseif ($hadFailure) {
            $status = AutomationExecutionStatus::Partial;
        }

        $execution->forceFill([
            'status' => $status->value,
            'finished_at' => now(),
            'error_code' => $status === AutomationExecutionStatus::Failed
                ? ($execution->actionExecutions()->where('status', 'failed')->latest('id')->value('error_code'))
                : null,
            'error_message' => $status === AutomationExecutionStatus::Failed
                ? ($execution->actionExecutions()->where('status', 'failed')->latest('id')->value('error_message'))
                : null,
        ])->save();

        return $execution->fresh() ?? $execution;
    }

    public function claim(int $executionId): ?AutomationExecution
    {
        return \App\Support\Automation\AutomationConnection::db()->transaction(function () use ($executionId): ?AutomationExecution {
            /** @var AutomationExecution|null $execution */
            $execution = AutomationExecution::query()
                ->whereKey($executionId)
                ->lockForUpdate()
                ->first();

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
                $stale = $started === null
                    || $started->lt(now()->subSeconds(self::STALE_PROCESSING_SECONDS));
                if (! $stale) {
                    return null;
                }
                $execution->attempt = (int) $execution->attempt + 1;
            }

            if ($execution->status === AutomationExecutionStatus::Failed->value) {
                $execution->attempt = (int) $execution->attempt + 1;
            }

            $execution->forceFill([
                'status' => AutomationExecutionStatus::Processing->value,
                'started_at' => $execution->started_at ?? now(),
                'finished_at' => null,
            ])->save();

            return $execution->fresh(['businessEvent', 'rule.actions']);
        });
    }

    public function retry(int $executionId): AutomationExecution
    {
        $execution = AutomationExecution::query()->findOrFail($executionId);
        if (! in_array($execution->status, [
            AutomationExecutionStatus::Failed->value,
            AutomationExecutionStatus::Partial->value,
        ], true)) {
            throw new AutomationException(
                BusinessHookErrorCode::ExecutionClaimFailed->value,
                'Only failed/partial executions can be retried.',
            );
        }

        $execution->forceFill([
            'status' => AutomationExecutionStatus::Pending->value,
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
            'context' => array_merge($execution->context ?? [], [
                'next_position' => 0,
                'previous_outputs' => [],
                'rerun_token' => (string) Str::uuid(),
            ]),
        ])->save();

        ExecuteAutomationRuleJob::dispatch($execution->id);

        return $execution;
    }

    /**
     * @param  list<int>  $delayedPositions
     * @param  array<string, mixed>  $previousOutputs
     */
    private function persistProgress(
        AutomationExecution $execution,
        int $nextPosition,
        array $delayedPositions,
        array $previousOutputs,
    ): void {
        $execution->forceFill([
            'context' => array_merge($execution->context ?? [], [
                'next_position' => $nextPosition,
                'delayed_positions' => $delayedPositions,
                'previous_outputs' => $previousOutputs,
            ]),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function startActionExecution(
        AutomationExecution $execution,
        AutomationRuleAction $action,
        array $input,
    ): AutomationActionExecution {
        $existing = AutomationActionExecution::query()
            ->where('automation_execution_id', $execution->id)
            ->where('position', $action->position)
            ->first();

        $payload = [
            'automation_rule_action_id' => $action->id,
            'action_code' => $action->action_code,
            'status' => AutomationActionExecutionStatus::Processing->value,
            'attempt' => $existing ? ((int) $existing->attempt + 1) : 1,
            'input_snapshot' => $this->redactor->redact($input),
            'output_snapshot' => null,
            'error_code' => null,
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
        ];

        if ($existing instanceof AutomationActionExecution) {
            $existing->forceFill($payload)->save();

            return $existing;
        }

        return AutomationActionExecution::query()->create(array_merge($payload, [
            'automation_execution_id' => $execution->id,
            'position' => (int) $action->position,
        ]));
    }

    private function finishActionExecution(
        AutomationActionExecution $actionExec,
        AutomationActionResult $result,
    ): void {
        $actionExec->forceFill([
            'status' => $result->success
                ? AutomationActionExecutionStatus::Completed->value
                : AutomationActionExecutionStatus::Failed->value,
            'output_snapshot' => $this->redactor->redact($result->output),
            'error_code' => $result->errorCode,
            'error_message' => $this->redactor->redactMessage($result->message),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     */
    private function recordActionStatus(
        AutomationExecution $execution,
        AutomationRuleAction $action,
        AutomationActionExecutionStatus $status,
        array $input,
        array $output,
        ?string $errorCode,
        string $message,
    ): void {
        AutomationActionExecution::query()->updateOrCreate(
            [
                'automation_execution_id' => $execution->id,
                'position' => (int) $action->position,
            ],
            [
                'automation_rule_action_id' => $action->id,
                'action_code' => $action->action_code,
                'status' => $status->value,
                'attempt' => 1,
                'input_snapshot' => $this->redactor->redact($input),
                'output_snapshot' => $this->redactor->redact($output),
                'error_code' => $errorCode,
                'error_message' => $message,
                'started_at' => now(),
                'finished_at' => now(),
            ],
        );
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

    /**
     * @param  array<string, mixed>|null  $manualAction
     */
    private function runManualSingleAction(
        AutomationExecution $execution,
        ?BusinessEvent $event,
        bool $dryRun,
    ): AutomationExecution {
        if ($execution->isCancellationRequested()) {
            $execution->forceFill([
                'status' => AutomationExecutionStatus::Cancelled->value,
                'error_code' => BusinessHookErrorCode::RuleDisabled->value,
                'error_message' => 'Cancellation requested — no side effects.',
                'finished_at' => now(),
            ])->save();

            return $execution->fresh() ?? $execution;
        }

        if (! $event instanceof BusinessEvent) {
            $this->failExecution($execution, BusinessHookErrorCode::ExecutionClaimFailed->value, 'Missing business event.');

            return $execution->fresh() ?? $execution;
        }

        /** @var array<string, mixed>|null $snapshot */
        $snapshot = is_array($execution->context['manual_action'] ?? null)
            ? $execution->context['manual_action']
            : null;

        $actionCode = (string) ($snapshot['action_code'] ?? $execution->action_code ?? '');
        if ($actionCode === '' || $snapshot === null) {
            $this->failExecution($execution, BusinessHookErrorCode::ActionNotRegistered->value, 'Manual action snapshot missing.');

            return $execution->fresh() ?? $execution;
        }

        $input = is_array($snapshot['input'] ?? null) ? $snapshot['input'] : [];
        $settings = is_array($snapshot['settings'] ?? null) ? $snapshot['settings'] : [];

        $loaded = $this->subjectLoader->load($event);
        if ($loaded['error_code'] !== null && $event->subject_id !== null) {
            $this->failExecution(
                $execution,
                (string) $loaded['error_code'],
                (string) ($loaded['error_message'] ?? 'Subject unavailable.'),
            );

            return $execution->fresh() ?? $execution;
        }

        $subject = $loaded['model'];
        $subjectData = $subject instanceof Model
            ? $this->subjectArray($subject)
            : ['id' => $event->subject_id, 'type' => $event->subject_type];

        $existingAction = AutomationActionExecution::query()
            ->where('automation_execution_id', $execution->id)
            ->where('position', 0)
            ->first();

        if ($existingAction instanceof AutomationActionExecution
            && $existingAction->status === AutomationActionExecutionStatus::Completed->value
        ) {
            $execution->forceFill([
                'status' => AutomationExecutionStatus::Completed->value,
                'finished_at' => $execution->finished_at ?? now(),
            ])->save();

            return $execution->fresh() ?? $execution;
        }

        if ($dryRun) {
            $this->recordManualActionStatus(
                $execution,
                $actionCode,
                AutomationActionExecutionStatus::Completed,
                $input,
                ['dry_run' => true],
                null,
                'Dry run — action not executed.',
            );
            $execution->forceFill([
                'status' => AutomationExecutionStatus::Completed->value,
                'finished_at' => now(),
            ])->save();

            return $execution->fresh() ?? $execution;
        }

        $actionExec = $this->startManualActionExecution($execution, $actionCode, $input, $existingAction);

        try {
            if (! $this->actionRegistry->has($actionCode)) {
                throw new AutomationException(
                    BusinessHookErrorCode::ActionNotRegistered->value,
                    "Action [{$actionCode}] is not registered.",
                );
            }

            $inputErrors = $this->actionRegistry->validateInput($actionCode, $input);
            if ($inputErrors !== []) {
                throw new AutomationException(
                    BusinessHookErrorCode::MissingRequiredInput->value,
                    implode(' ', $inputErrors),
                );
            }

            $handler = $this->actionRegistry->resolveHandler($actionCode);
            $actionContext = new AutomationActionContext(
                businessEvent: $event,
                rule: null,
                execution: $execution,
                subject: $subject,
                subjectData: $subjectData,
                siteId: $event->site_id,
                projectId: $event->project_id,
                actorId: $execution->initiated_by_user_id
                    ?? (isset(($event->context ?? [])['actor_id']) ? (int) $event->context['actor_id'] : null),
                correlationId: isset(($execution->context ?? [])['correlation_id'])
                    ? (string) $execution->context['correlation_id']
                    : (isset(($event->context ?? [])['correlation_id'])
                        ? (string) $event->context['correlation_id']
                        : null),
                automationDepth: (int) (($event->context ?? [])['automation_depth'] ?? 0),
                previousOutputs: [],
                dryRun: false,
            );

            $result = $handler->handle($actionContext, $input, $settings);
        } catch (AutomationException $e) {
            $result = AutomationActionResult::failure($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            $result = AutomationActionResult::failure('AUTOMATION_ACTION_EXCEPTION', $e->getMessage());
        }

        $this->finishActionExecution($actionExec, $result);

        $execution->forceFill([
            'status' => $result->success
                ? AutomationExecutionStatus::Completed->value
                : AutomationExecutionStatus::Failed->value,
            'error_code' => $result->success ? null : $result->errorCode,
            'error_message' => $result->success ? null : $this->redactor->redactMessage($result->message),
            'finished_at' => now(),
            'context' => array_merge($execution->context ?? [], [
                'next_position' => 1,
                'previous_outputs' => ['0' => $result->output],
            ]),
        ])->save();

        return $execution->fresh() ?? $execution;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function startManualActionExecution(
        AutomationExecution $execution,
        string $actionCode,
        array $input,
        ?AutomationActionExecution $existing,
    ): AutomationActionExecution {
        $payload = [
            'automation_rule_action_id' => null,
            'action_code' => $actionCode,
            'status' => AutomationActionExecutionStatus::Processing->value,
            'attempt' => $existing ? ((int) $existing->attempt + 1) : 1,
            'input_snapshot' => $this->redactor->redact($input),
            'output_snapshot' => null,
            'error_code' => null,
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
        ];

        if ($existing instanceof AutomationActionExecution) {
            $existing->forceFill($payload)->save();

            return $existing;
        }

        return AutomationActionExecution::query()->create(array_merge($payload, [
            'automation_execution_id' => $execution->id,
            'position' => 0,
        ]));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     */
    private function recordManualActionStatus(
        AutomationExecution $execution,
        string $actionCode,
        AutomationActionExecutionStatus $status,
        array $input,
        array $output,
        ?string $errorCode,
        string $message,
    ): void {
        AutomationActionExecution::query()->updateOrCreate(
            [
                'automation_execution_id' => $execution->id,
                'position' => 0,
            ],
            [
                'automation_rule_action_id' => null,
                'action_code' => $actionCode,
                'status' => $status->value,
                'attempt' => 1,
                'input_snapshot' => $this->redactor->redact($input),
                'output_snapshot' => $this->redactor->redact($output),
                'error_code' => $errorCode,
                'error_message' => $this->redactor->redactMessage($message),
                'started_at' => now(),
                'finished_at' => now(),
            ],
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

        /** @var BusinessEventDispatcher $dispatcher */
        $dispatcher = app(BusinessEventDispatcher::class);

        foreach ($result->dispatchEvents as $followUp) {
            $eventName = (string) ($followUp['event_name'] ?? '');
            if ($eventName === '') {
                continue;
            }

            try {
                $childContext = $this->loopGuard->childContext(
                    array_merge($parentEvent->context ?? [], [
                        'event_uuid' => $parentEvent->event_uuid,
                    ]),
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
                Log::warning('automation.follow_up_event_failed', [
                    'parent_event' => $parentEvent->event_uuid,
                    'event_name' => $eventName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
