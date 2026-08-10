<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationTriggerType;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationConditionEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class AutomationRuleMatcher
{
    public function __construct(
        private readonly AutomationConditionEngine $conditions,
    ) {}

    /**
     * @return Collection<int, AutomationRule>
     */
    public function match(BusinessEvent $event, array $subjectData = []): Collection
    {
        $sources = $this->buildSources($event, $subjectData);

        return AutomationRule::query()
            ->where('event_name', $event->event_name)
            ->where('is_enabled', true)
            ->where(function ($query) use ($event): void {
                $query->whereNull('site_id');
                if ($event->site_id !== null) {
                    $query->orWhere('site_id', $event->site_id);
                }
            })
            ->when(
                $event->event_name === BusinessEventName::ScheduleTriggered->value,
                static fn ($query) => $query->where('trigger_type', AutomationTriggerType::Schedule->value),
                // Manual trigger_type never matches domain events — only ManualAutomationDispatcher.
                static fn ($query) => $query->where(function ($sub): void {
                    $sub->where('trigger_type', AutomationTriggerType::Event->value)
                        ->orWhereNull('trigger_type');
                }),
            )
            ->orderBy('priority')
            ->orderBy('id')
            ->with('actions')
            ->get()
            ->filter(function (AutomationRule $rule) use ($sources, $event): bool {
                try {
                    $matched = $this->conditions->matches($rule->conditions, $sources);
                } catch (\Throwable $e) {
                    Log::warning('automation.rule.condition_error', [
                        'rule_id' => $rule->id,
                        'event_uuid' => $event->event_uuid,
                        'error' => $e->getMessage(),
                    ]);

                    return false;
                }

                if (! $matched) {
                    Log::debug('automation.rule.skipped_unmatched', [
                        'rule_id' => $rule->id,
                        'rule_code' => $rule->code,
                        'event_uuid' => $event->event_uuid,
                    ]);
                }

                return $matched;
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $subjectData
     * @return array{
     *   event: array<string, mixed>,
     *   payload: array<string, mixed>,
     *   context: array<string, mixed>,
     *   subject: array<string, mixed>,
     *   previous: array<string, mixed>
     * }
     */
    public function buildSources(BusinessEvent $event, array $subjectData = []): array
    {
        return [
            'event' => [
                'id' => $event->id,
                'event_uuid' => $event->event_uuid,
                'event_name' => $event->event_name,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'site_id' => $event->site_id,
                'project_id' => $event->project_id,
            ],
            'payload' => $event->payload ?? [],
            'context' => $event->context ?? [],
            'subject' => $subjectData !== [] ? $subjectData : [
                'id' => $event->subject_id,
                'type' => $event->subject_type,
            ],
            'previous' => [],
        ];
    }
}
