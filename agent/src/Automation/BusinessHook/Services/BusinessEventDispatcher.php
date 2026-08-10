<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationEventDispatchResult;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationEventDispatchOutcome;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationRunMode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationLoopGuard;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationSnapshotSanitizer;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class BusinessEventDispatcher
{
    public function __construct(
        private readonly BusinessEventRegistry $eventRegistry,
        private readonly AutomationRuleMatcher $matcher,
        private readonly AutomationExecutionService $executionService,
        private readonly AutomationLoopGuard $loopGuard,
        private readonly AutomationSnapshotSanitizer $sanitizer,
    ) {}

    /**
     * BC: trả BusinessEvent. Outcome event nên dùng dispatchWithOutcome (không throw SKIPPED_NO_RULE).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function dispatch(
        string $eventName,
        Model|string|null $subject = null,
        array $payload = [],
        array $context = [],
        ?string $eventUuid = null,
    ): BusinessEvent {
        $result = $this->dispatchWithOutcome($eventName, $subject, $payload, $context, $eventUuid);

        if ($result->event instanceof BusinessEvent) {
            return $result->event;
        }

        throw new AutomationException(
            $result->errorCode ?? BusinessHookErrorCode::EventNotRegistered->value,
            $result->message ?? "Failed to dispatch [{$eventName}].",
        );
    }

    /**
     * Typed dispatch — SKIPPED_NO_RULE không phải exception.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function dispatchWithOutcome(
        string $eventName,
        Model|string|null $subject = null,
        array $payload = [],
        array $context = [],
        ?string $eventUuid = null,
    ): AutomationEventDispatchResult {
        if (! $this->eventRegistry->has($eventName)) {
            return new AutomationEventDispatchResult(
                outcome: AutomationEventDispatchOutcome::RejectedInvalidPayload,
                message: "Business event [{$eventName}] is not registered.",
                errorCode: BusinessHookErrorCode::EventNotRegistered->value,
            );
        }

        $eventUuid ??= (string) Str::uuid();

        $existing = BusinessEvent::query()->where('event_uuid', $eventUuid)->first();
        if ($existing instanceof BusinessEvent) {
            return new AutomationEventDispatchResult(
                outcome: AutomationEventDispatchOutcome::Deduplicated,
                event: $existing,
                message: 'Event uuid already recorded.',
                matchedRules: (int) (($existing->context['matched_rules'] ?? 0)),
            );
        }

        $subjectType = null;
        $subjectId = null;
        $subjectData = [];

        if ($subject instanceof Model) {
            $subjectType = $subject::class;
            $subjectId = (int) $subject->getKey();
            $subjectData = $this->extractSubjectData($subject);
        } elseif (is_string($subject) && $subject !== '') {
            $subjectType = $subject;
        }

        $siteId = $this->nullableInt($payload['site_id'] ?? $context['site_id'] ?? $subjectData['site_id'] ?? null);
        $projectId = $this->nullableInt($payload['project_id'] ?? $context['project_id'] ?? $subjectData['project_id'] ?? null);

        $context = $this->enrichContext($context, $eventUuid, $eventName);

        try {
            $context = $this->loopGuard->assertAllowed($context, $eventName, null);
        } catch (AutomationException $e) {
            Log::warning('automation.event.blocked', [
                'event_name' => $eventName,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);

            return new AutomationEventDispatchResult(
                outcome: AutomationEventDispatchOutcome::BlockedLoop,
                message: $e->getMessage(),
                errorCode: $e->errorCode,
            );
        }

        $payloadErrors = $this->eventRegistry->validatePayload($eventName, $payload);
        if ($payloadErrors !== []) {
            Log::debug('automation.event.payload_soft_invalid', [
                'event_name' => $eventName,
                'errors' => $payloadErrors,
            ]);
        }

        try {
            $event = BusinessEvent::query()->create([
                'event_uuid' => $eventUuid,
                'event_name' => $eventName,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'site_id' => $siteId,
                'project_id' => $projectId,
                'payload' => $this->sanitizer->sanitize($payload) ?? [],
                'context' => $this->sanitizer->sanitize($context) ?? [],
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('automation.event.persist_failed', [
                'event_name' => $eventName,
                'error' => $e->getMessage(),
            ]);

            return new AutomationEventDispatchResult(
                outcome: AutomationEventDispatchOutcome::FailedToDispatch,
                message: $e->getMessage(),
                errorCode: 'EVENT_PERSIST_FAILED',
            );
        }

        $scheduleOutcome = null;
        $schedule = function () use ($event, $subjectData, &$scheduleOutcome): void {
            $scheduleOutcome = $this->scheduleMatchingRules($event, $subjectData);
        };

        try {
            \Omnichannel\Addons\Content\Support\SourceAwareAfterCommit::run($schedule);

            $openTxn = false;
            try {
                $openTxn = \App\Support\Automation\AutomationConnection::db()->transactionLevel() > 0
                    || \Illuminate\Support\Facades\DB::connection('omi_seo_ai')->transactionLevel() > 0;
            } catch (\Throwable) {
                $openTxn = \App\Support\Automation\AutomationConnection::db()->transactionLevel() > 0;
            }

            if ($openTxn) {
                return new AutomationEventDispatchResult(
                    outcome: AutomationEventDispatchOutcome::Queued,
                    event: $event,
                    message: 'Event persisted; rule match scheduled after source commit.',
                );
            }

            // schedule already ran synchronously inside SourceAwareAfterCommit when no open txn.
        } catch (\Throwable $e) {
            Log::error('automation.event.schedule_failed', [
                'event_name' => $eventName,
                'event_uuid' => $event->event_uuid,
                'error' => $e->getMessage(),
            ]);

            return new AutomationEventDispatchResult(
                outcome: AutomationEventDispatchOutcome::FailedToDispatch,
                event: $event,
                message: $e->getMessage(),
                errorCode: 'EVENT_SCHEDULE_FAILED',
            );
        }

        return $scheduleOutcome instanceof AutomationEventDispatchResult
            ? $scheduleOutcome
            : new AutomationEventDispatchResult(
                outcome: AutomationEventDispatchOutcome::Queued,
                event: $event,
            );
    }

    /**
     * @param  array<string, mixed>  $subjectData
     */
    private function scheduleMatchingRules(BusinessEvent $event, array $subjectData): AutomationEventDispatchResult
    {
        $rules = $this->matcher->match($event, $subjectData);

        if ($rules->isEmpty()) {
            $event->forceFill([
                'context' => array_merge($event->context ?? [], [
                    'matched_rules' => 0,
                    'automation_match_status' => 'skipped',
                    'automation_skip_code' => BusinessHookErrorCode::RuleNotFound->value,
                    'reason' => 'no_enabled_rule',
                ]),
            ])->save();

            $logContext = [
                'event_uuid' => $event->event_uuid,
                'event_name' => $event->event_name,
                'automation_skip_code' => BusinessHookErrorCode::RuleNotFound->value,
                'outcome' => AutomationEventDispatchOutcome::SkippedNoRule->value,
            ];
            if ($this->isOptionalConsumerEvent((string) $event->event_name)) {
                Log::debug('automation.event.skipped_no_rule', $logContext);
            } else {
                Log::info('automation.event.skipped_no_rule', $logContext);
            }

            return new AutomationEventDispatchResult(
                outcome: AutomationEventDispatchOutcome::SkippedNoRule,
                event: $event,
                message: 'No enabled automation rule for event.',
                errorCode: BusinessHookErrorCode::RuleNotFound->value,
                matchedRules: 0,
            );
        }

        $event->forceFill([
            'context' => array_merge($event->context ?? [], [
                'matched_rules' => $rules->count(),
                'automation_match_status' => 'matched',
            ]),
        ])->save();

        $queued = 0;
        foreach ($rules as $rule) {
            try {
                $execution = $this->executionService->createPendingExecution($event, $rule);
                if (! $execution instanceof AutomationExecution) {
                    continue;
                }

                if ($rule->run_mode === AutomationRunMode::Sync->value) {
                    $this->executionService->run($execution->id);
                } else {
                    ExecuteAutomationRuleJob::dispatch($execution->id);
                }
                $queued++;
            } catch (\Throwable $e) {
                Log::error('automation.schedule_failed', [
                    'event_uuid' => $event->event_uuid,
                    'rule_id' => $rule->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new AutomationEventDispatchResult(
            outcome: AutomationEventDispatchOutcome::Queued,
            event: $event,
            message: "Matched {$rules->count()} rule(s), queued {$queued}.",
            matchedRules: $rules->count(),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function enrichContext(array $context, string $eventUuid, string $eventName): array
    {
        $context['event_uuid'] = $context['event_uuid'] ?? $eventUuid;
        $context['root_event_uuid'] = $context['root_event_uuid'] ?? $eventUuid;
        $context['automation_depth'] = (int) ($context['automation_depth'] ?? 0);
        $context['automation_chain'] = is_array($context['automation_chain'] ?? null)
            ? $context['automation_chain']
            : [];
        $context['triggered_event_name'] = $eventName;

        if (! isset($context['actor_id']) && auth()->id() !== null) {
            $context['actor_id'] = (int) auth()->id();
        }

        if (! isset($context['correlation_id'])) {
            $context['correlation_id'] = (string) Str::uuid();
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSubjectData(Model $subject): array
    {
        if ($subject instanceof SeoArticle) {
            return [
                'id' => (int) $subject->id,
                'site_id' => $this->nullableInt($subject->site_id ?? null),
                'post_type' => $subject->post_type ?? null,
                'status' => $subject->status ?? null,
                'title' => $subject->title ?? null,
            ];
        }

        if ($subject instanceof SeoProjectTask) {
            return [
                'id' => (int) $subject->id,
                'project_id' => $this->nullableInt($subject->project_id ?? null),
                'site_id' => $this->nullableInt($subject->site_id ?? null),
                'article_id' => $this->nullableInt($subject->article_id ?? null),
                'status' => $subject->status ?? null,
                'post_type' => $subject->post_type ?? null,
            ];
        }

        if ($subject instanceof SeoProjectRun) {
            return [
                'id' => (int) $subject->id,
                'project_id' => $this->nullableInt($subject->project_id ?? null),
                'status' => $subject->status ?? null,
            ];
        }

        return [
            'id' => (int) $subject->getKey(),
        ];
    }

    private function isOptionalConsumerEvent(string $eventName): bool
    {
        return in_array($eventName, [
            BusinessEventName::WordpressSyncStarted->value,
            BusinessEventName::WordpressSynced->value,
            BusinessEventName::WordpressSyncFailed->value,
            BusinessEventName::SeoAnalysisStarted->value,
            BusinessEventName::SeoAnalysisCompleted->value,
            BusinessEventName::SeoAnalysisFailed->value,
            BusinessEventName::MediaProcessed->value,
            BusinessEventName::MediaFailed->value,
            BusinessEventName::ContentProjectRunStarted->value,
            BusinessEventName::ContentProjectRunCompleted->value,
            BusinessEventName::ContentProjectRunFailed->value,
        ], true);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
