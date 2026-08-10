<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentMetricEvent;
use App\Support\RuntimeLogger;
use Illuminate\Support\Str;
use Throwable;

/**
 * Metric event recorder — controlled keys/dimensions, fail-open.
 */
final class AgentMetricRecorder
{
    public function __construct(
        private readonly AgentObservabilityRedactor $redactor = new AgentObservabilityRedactor,
        private readonly ?AgentObservabilityEventBus $bus = null,
    ) {}

    /**
     * @param  array<string, mixed>  $dimensions
     */
    public function record(
        string $metricKey,
        float $value = 1.0,
        array $dimensions = [],
        ?string $traceId = null,
        ?int $siteId = null,
        ?int $actorUserId = null,
        string $severity = 'info',
    ): bool {
        if (! AgentObservabilityCatalog::isMetricKey($metricKey)) {
            return false;
        }
        $dims = $this->redactor->filterDimensions($dimensions);
        if (count($dimensions) > count($dims) && $dimensions !== []) {
            // high-cardinality / unknown dims dropped — not an error
        }

        try {
            SeoAgentMetricEvent::query()->create([
                'hash_id' => 'amet_'.Str::lower((string) Str::ulid()),
                'metric_key' => $metricKey,
                'trace_id' => $traceId,
                'site_id' => $siteId,
                'actor_user_id' => $actorUserId,
                'dimensions' => $dims,
                'value' => $value,
                'severity' => $severity,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            RuntimeLogger::warning('agent.metric.record_failed', [
                'metric_key' => $metricKey,
                'exception' => $e::class,
            ]);

            return false;
        }

        $this->bus?->dispatch([
            'event_type' => 'metric.recorded',
            'trace_id' => $traceId ?? 'none',
            'attributes' => ['metric_key' => $metricKey, 'value' => $value],
            'severity' => $severity,
        ]);

        return true;
    }
}
