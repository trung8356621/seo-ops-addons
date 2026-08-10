<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\ManualAutomationDispatchResult;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationTriggerType;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationSnapshotSanitizer;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationExecutionResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manual UI/API trigger → Automation Engine.
 * Always runs AutomationAvailabilityGate before create/enqueue.
 */
final class ManualAutomationDispatcher
{
    public function __construct(
        private readonly AutomationActionRegistry $actionRegistry,
        private readonly AutomationSnapshotSanitizer $sanitizer,
        private readonly AutomationAvailabilityGate $availabilityGate,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $context
     */
    public function dispatch(
        string $actionCode,
        Model $subject,
        User $actor,
        array $input = [],
        array $settings = [],
        array $context = [],
        ?string $idempotencyKey = null,
        ?string $initiatedFrom = null,
    ): ManualAutomationDispatchResult {
        $availability = $this->availabilityGate->checkManual($actionCode, $subject, $actor, $input);

        if (! $availability->allowed) {
            if ($availability->code === BusinessHookErrorCode::ExecutionAlreadyActive->value
                && ($availability->context['dedupe'] ?? false) === true
            ) {
                $activeId = (int) ($availability->context['active_execution_id'] ?? 0);
                $active = $activeId > 0 ? AutomationExecution::query()->find($activeId) : null;
                if ($active instanceof AutomationExecution) {
                    return ManualAutomationDispatchResult::fromExecution(
                        ManualAutomationDispatchResult::STATUS_DEDUPLICATED,
                        $active,
                        $actionCode,
                        __('seo-content-ai::filament.automation.gate.execution_already_active', ['action' => $actionCode]),
                        BusinessHookErrorCode::ExecutionAlreadyActive->value,
                        $availability->ruleCode,
                        $this->historyUrl($active),
                    );
                }
            }

            return ManualAutomationDispatchResult::blocked(
                $actionCode,
                $availability->code,
                $availability->message,
                $availability->ruleCode,
                $availability->context,
            );
        }

        $definition = $this->actionRegistry->get($actionCode);
        $inputErrors = $this->actionRegistry->validateInput($actionCode, $input);
        if ($inputErrors !== []) {
            return ManualAutomationDispatchResult::blocked(
                $actionCode,
                BusinessHookErrorCode::MissingRequiredInput->value,
                implode(' ', $inputErrors),
                $availability->ruleCode,
            );
        }

        $siteId = $this->resolveSiteId($subject, $input, $context);
        $contentVersion = $this->contentVersion($subject, $input);
        $idempotencyKey ??= $this->buildIdempotencyKey($actionCode, $subject, $contentVersion, $definition);

        $existing = AutomationExecution::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing instanceof AutomationExecution) {
            if (in_array((string) $existing->status, [
                AutomationExecutionStatus::Pending->value,
                AutomationExecutionStatus::Processing->value,
            ], true)) {
                return ManualAutomationDispatchResult::fromExecution(
                    ManualAutomationDispatchResult::STATUS_DEDUPLICATED,
                    $existing,
                    $actionCode,
                    __('seo-content-ai::filament.automation.gate.execution_already_active', ['action' => $actionCode]),
                    BusinessHookErrorCode::ExecutionAlreadyActive->value,
                    $availability->ruleCode,
                    $this->historyUrl($existing),
                );
            }
        }

        $initiatedFrom = $initiatedFrom
            ?? (string) ($context['initiated_from'] ?? 'manual_dispatcher');
        $requestId = (string) ($context['request_id'] ?? Str::uuid());
        $correlationId = (string) ($context['correlation_id'] ?? Str::uuid());
        $eventUuid = (string) ($context['event_uuid'] ?? Str::uuid());

        $checksum = $definition->definitionChecksum();
        $manualSnapshot = [
            'action_code' => $actionCode,
            'action_version' => $checksum,
            'handler_class' => $definition->handlerClass,
            'input' => $this->sanitizer->sanitize($input) ?? [],
            'settings' => $this->sanitizer->sanitize($settings) ?? [],
            'queue' => $definition->defaultQueue,
            'timeout' => $definition->timeout,
            'retry_policy' => [
                'tries' => 3,
                'backoff' => [10, 30, 60],
            ],
            'resolved_rule_id' => $availability->ruleId,
            'resolved_rule_code' => $availability->ruleCode,
            'published_version_id' => $availability->publishedVersionId,
        ];

        try {
            $execution = \App\Support\Automation\AutomationConnection::db()->transaction(function () use (
                $actionCode,
                $subject,
                $actor,
                $siteId,
                $input,
                $context,
                $idempotencyKey,
                $initiatedFrom,
                $requestId,
                $correlationId,
                $eventUuid,
                $manualSnapshot,
                $checksum,
                $availability,
            ): AutomationExecution {
                $again = AutomationExecution::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($again instanceof AutomationExecution) {
                    return $again;
                }

                $event = BusinessEvent::query()->create([
                    'event_uuid' => $eventUuid,
                    'event_name' => BusinessEventName::ManualActionRequested->value,
                    'subject_type' => $subject::class,
                    'subject_id' => (int) $subject->getKey(),
                    'site_id' => $siteId,
                    'project_id' => $this->nullableInt($input['project_id'] ?? $context['project_id'] ?? null),
                    'payload' => $this->sanitizer->sanitize(array_merge($input, [
                        'action_code' => $actionCode,
                    ])) ?? [],
                    'context' => $this->sanitizer->sanitize([
                        'trigger_type' => AutomationTriggerType::Manual->value,
                        'initiated_by_user_id' => (int) $actor->id,
                        'initiated_from' => $initiatedFrom,
                        'request_id' => $requestId,
                        'correlation_id' => $correlationId,
                        'actor_id' => (int) $actor->id,
                        'event_uuid' => $eventUuid,
                        'root_event_uuid' => $eventUuid,
                        'automation_depth' => 0,
                        'automation_chain' => [],
                        'action_code' => $actionCode,
                        'resolved_rule_code' => $availability->ruleCode,
                    ]) ?? [],
                    'occurred_at' => now(),
                    'created_at' => now(),
                ]);

                return AutomationExecution::query()->create([
                    'execution_uuid' => (string) Str::uuid(),
                    'business_event_id' => $event->id,
                    'automation_rule_id' => $availability->ruleId,
                    'automation_rule_version_id' => $availability->publishedVersionId,
                    'rule_version' => 0,
                    'status' => AutomationExecutionStatus::Pending->value,
                    'trigger_type' => AutomationTriggerType::Manual->value,
                    'initiated_by_user_id' => (int) $actor->id,
                    'initiated_from' => $initiatedFrom,
                    'action_code' => $actionCode,
                    'attempt' => 1,
                    'idempotency_key' => $idempotencyKey,
                    'context' => [
                        'trigger_type' => AutomationTriggerType::Manual->value,
                        'initiated_by_user_id' => (int) $actor->id,
                        'initiated_from' => $initiatedFrom,
                        'request_id' => $requestId,
                        'correlation_id' => $correlationId,
                        'event_uuid' => $eventUuid,
                        'idempotency_key' => $idempotencyKey,
                        'action_code' => $actionCode,
                        'action_version' => $checksum,
                        'manual_action' => $manualSnapshot,
                        'resolved_rule_id' => $availability->ruleId,
                        'resolved_rule_code' => $availability->ruleCode,
                        'next_position' => 0,
                        'delayed_positions' => [],
                        'previous_outputs' => [],
                    ],
                ]);
            });
        } catch (\Throwable $e) {
            throw new AutomationException(
                BusinessHookErrorCode::ExecutionClaimFailed->value,
                'Failed to create manual automation execution: '.$e->getMessage(),
                previous: $e,
            );
        }

        $fresh = $execution->fresh() ?? $execution;
        if ((string) $fresh->status === AutomationExecutionStatus::Pending->value
            && $fresh->started_at === null
        ) {
            $queue = (string) ($manualSnapshot['queue'] ?? 'automation');
            ExecuteAutomationRuleJob::dispatch($fresh->id)->onQueue($queue);
        }

        $status = in_array((string) $fresh->status, [
            AutomationExecutionStatus::Pending->value,
            AutomationExecutionStatus::Processing->value,
        ], true) && (int) $fresh->id === (int) $execution->id
            ? ManualAutomationDispatchResult::STATUS_DISPATCHED
            : ManualAutomationDispatchResult::STATUS_DEDUPLICATED;

        return ManualAutomationDispatchResult::fromExecution(
            $status,
            $fresh,
            $actionCode,
            $status === ManualAutomationDispatchResult::STATUS_DISPATCHED
                ? __('seo-content-ai::filament.automation.gate.dispatched')
                : __('seo-content-ai::filament.automation.gate.execution_already_active', ['action' => $actionCode]),
            'OK',
            $availability->ruleCode,
            $this->historyUrl($fresh),
        );
    }

    private function historyUrl(AutomationExecution $execution): ?string
    {
        try {
            return AutomationExecutionResource::getUrl('view', ['record' => $execution]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     */
    private function resolveSiteId(Model $subject, array $input, array $context): ?int
    {
        if ($subject instanceof SeoArticle) {
            return $this->nullableInt($subject->site_id ?? null);
        }

        return $this->nullableInt($input['site_id'] ?? $context['site_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function contentVersion(Model $subject, array $input): string
    {
        if (isset($input['content_version']) && is_string($input['content_version']) && $input['content_version'] !== '') {
            return $input['content_version'];
        }

        if ($subject instanceof SeoArticle) {
            $updated = $subject->updated_at?->getTimestamp() ?? 0;
            $bodyHash = hash('sha256', (string) ($subject->body ?? ''));

            return substr(hash('sha256', $updated.'|'.$bodyHash), 0, 16);
        }

        return (string) $subject->getKey();
    }

    private function buildIdempotencyKey(
        string $actionCode,
        Model $subject,
        string $contentVersion,
        AutomationActionDefinition $definition,
    ): string {
        $scope = $definition->manualIdempotencyScope;
        $raw = match ($scope) {
            'action' => 'manual:'.$actionCode,
            default => 'manual:'.$actionCode.':'.$subject::class.':'.$subject->getKey().':'.$contentVersion,
        };

        return hash('sha256', $raw);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
