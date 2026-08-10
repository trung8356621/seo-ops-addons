<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

use App\Support\RuntimeLogger;
use Throwable;

/**
 * Allowlisted Agent observability event bus — side-channel only.
 */
final class AgentObservabilityEventBus
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    public function __construct(
        private readonly AgentObservabilityRedactor $redactor = new AgentObservabilityRedactor,
        private readonly bool $failOpen = true,
    ) {}

    public function subscribe(string $eventType, callable $listener): void
    {
        if (! AgentObservabilityCatalog::isEventType($eventType)) {
            return;
        }
        $this->listeners[$eventType][] = $listener;
    }

    /**
     * @param  array{
     *     event_type: string,
     *     trace_id: string,
     *     span_id?: ?string,
     *     parent_span_id?: ?string,
     *     actor?: array<string, mixed>,
     *     site?: array<string, mixed>,
     *     references?: array<string, mixed>,
     *     attributes?: array<string, mixed>,
     *     severity?: string,
     *     occurred_at?: string
     * }  $event
     * @return array{accepted: bool, persisted_claimed: bool, reason?: string}
     */
    public function dispatch(array $event): array
    {
        $type = (string) ($event['event_type'] ?? '');
        if (! AgentObservabilityCatalog::isEventType($type)) {
            return ['accepted' => false, 'persisted_claimed' => false, 'reason' => 'event_type_not_allowlisted'];
        }
        if (! isset($event['trace_id']) || ! is_string($event['trace_id']) || $event['trace_id'] === '') {
            return ['accepted' => false, 'persisted_claimed' => false, 'reason' => 'trace_id_required'];
        }

        $sanitized = [
            'event_type' => $type,
            'trace_id' => $event['trace_id'],
            'span_id' => $event['span_id'] ?? null,
            'parent_span_id' => $event['parent_span_id'] ?? null,
            'actor' => $this->redactor->redact(is_array($event['actor'] ?? null) ? $event['actor'] : []),
            'site' => $this->redactor->redact(is_array($event['site'] ?? null) ? $event['site'] : []),
            'references' => $this->redactor->redact(is_array($event['references'] ?? null) ? $event['references'] : []),
            'attributes' => $this->redactor->redact(is_array($event['attributes'] ?? null) ? $event['attributes'] : []),
            'severity' => (string) ($event['severity'] ?? 'info'),
            'occurred_at' => (string) ($event['occurred_at'] ?? gmdate(DATE_ATOM)),
        ];

        $isSecurity = $type === 'security.audit' || $type === 'policy.violation'
            || in_array($sanitized['severity'], ['high', 'critical'], true);

        try {
            foreach ($this->listeners[$type] ?? [] as $index => $listener) {
                try {
                    $listener($sanitized);
                } catch (Throwable $e) {
                    RuntimeLogger::warning('agent.observability.listener_failed', [
                        'event_type' => $type,
                        'listener_index' => $index,
                        'exception' => $e::class,
                    ]);
                    if ($isSecurity) {
                        RuntimeLogger::warning('agent.observability.security_fallback', [
                            'event_type' => $type,
                            'trace_id' => $sanitized['trace_id'],
                            'severity' => $sanitized['severity'],
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            if ($isSecurity) {
                RuntimeLogger::warning('agent.observability.security_fallback', [
                    'event_type' => $type,
                    'trace_id' => $event['trace_id'],
                    'exception' => $e::class,
                ]);
            }
            if (! $this->failOpen && ! $isSecurity) {
                return ['accepted' => false, 'persisted_claimed' => false, 'reason' => 'dispatch_failed'];
            }
        }

        // Bus never claims DB persistence — callers persist separately.
        return ['accepted' => true, 'persisted_claimed' => false];
    }
}
