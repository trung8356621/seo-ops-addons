<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentTrace;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentTraceSpan;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use App\Support\RuntimeLogger;
use Illuminate\Support\Str;
use Throwable;

/**
 * Trace/span writer — fail-open for normal telemetry.
 */
final class AgentTraceService
{
    public function __construct(
        private readonly AgentObservabilityRedactor $redactor = new AgentObservabilityRedactor,
        private readonly ?AgentObservabilityEventBus $bus = null,
        private readonly ?AgentPlanningVersionRegistry $versions = null,
    ) {}

    /**
     * @param  array<string, mixed>  $references
     */
    public function startTrace(
        ?AgentWorkspaceContext $context,
        string $rootSpanType = 'request',
        array $references = [],
    ): string {
        $traceId = 'atrace_'.Str::lower((string) Str::ulid());
        if (! AgentObservabilityCatalog::isSpanType($rootSpanType)) {
            $rootSpanType = 'request';
        }

        try {
            SeoAgentTrace::query()->create([
                'trace_id' => $traceId,
                'connection_hash' => $context?->connectionId !== null
                    ? hash('sha256', (string) $context->connectionId)
                    : null,
                'tenant_id' => $context?->tenantId,
                'site_id' => $context?->siteId,
                'site_ref' => $context?->siteRef,
                'actor_user_id' => $context?->actorUserId,
                'root_span_type' => $rootSpanType,
                'status' => 'open',
                'references_json' => $this->redactor->redact($references),
                'version_snapshot' => ($this->versions ?? new AgentPlanningVersionRegistry)->snapshot(),
                'started_at' => now(),
            ]);
        } catch (Throwable $e) {
            RuntimeLogger::warning('agent.trace.start_failed', [
                'trace_id' => $traceId,
                'exception' => $e::class,
            ]);
        }

        $this->bus?->dispatch([
            'event_type' => 'trace.started',
            'trace_id' => $traceId,
            'site' => ['site_ref' => $context?->siteRef, 'site_id' => $context?->siteId],
            'actor' => ['actor_user_id' => $context?->actorUserId],
            'references' => $references,
            'severity' => 'info',
        ]);

        return $traceId;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $references
     */
    public function startSpan(
        string $traceId,
        string $spanType,
        ?string $parentSpanId = null,
        array $attributes = [],
        array $references = [],
    ): string {
        $spanId = 'aspan_'.Str::lower((string) Str::ulid());
        if (! AgentObservabilityCatalog::isSpanType($spanType)) {
            return $spanId; // drop persist for unknown types
        }

        try {
            SeoAgentTraceSpan::query()->create([
                'trace_id' => $traceId,
                'span_id' => $spanId,
                'parent_span_id' => $parentSpanId,
                'span_type' => $spanType,
                'status' => 'running',
                'attributes' => $this->redactor->redact($attributes),
                'references_json' => $this->redactor->redact($references),
                'started_at' => now(),
            ]);
        } catch (Throwable $e) {
            RuntimeLogger::warning('agent.trace.span_start_failed', [
                'trace_id' => $traceId,
                'exception' => $e::class,
            ]);
        }

        $this->bus?->dispatch([
            'event_type' => 'span.started',
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'attributes' => ['span_type' => $spanType],
            'severity' => 'info',
        ]);

        return $spanId;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function endSpan(
        string $traceId,
        string $spanId,
        string $status = 'ok',
        array $attributes = [],
        ?string $errorCode = null,
        ?int $durationMs = null,
    ): void {
        try {
            $span = SeoAgentTraceSpan::query()
                ->where('trace_id', $traceId)
                ->where('span_id', $spanId)
                ->first();
            if ($span === null) {
                return;
            }
            $started = $span->started_at?->getTimestamp();
            $duration = $durationMs ?? ($started !== null
                ? max(0, (int) (microtime(true) * 1000) - ($started * 1000))
                : null);
            $merged = array_merge(is_array($span->attributes) ? $span->attributes : [], $attributes);
            $span->fill([
                'status' => $status,
                'attributes' => $this->redactor->redact($merged),
                'error_code' => $errorCode,
                'duration_ms' => $duration,
                'finished_at' => now(),
            ]);
            $span->save();
        } catch (Throwable $e) {
            RuntimeLogger::warning('agent.trace.span_end_failed', [
                'trace_id' => $traceId,
                'exception' => $e::class,
            ]);
        }

        $this->bus?->dispatch([
            'event_type' => 'span.finished',
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'attributes' => ['status' => $status, 'error_code' => $errorCode],
            'severity' => $status === 'ok' ? 'info' : 'warning',
        ]);
    }

    public function finishTrace(string $traceId, string $status = 'ok'): void
    {
        try {
            $trace = SeoAgentTrace::query()->where('trace_id', $traceId)->first();
            if ($trace === null) {
                return;
            }
            $started = $trace->started_at?->getTimestamp();
            $duration = $started !== null
                ? max(0, (int) (microtime(true) * 1000) - ($started * 1000))
                : null;
            $trace->fill([
                'status' => $status,
                'finished_at' => now(),
                'duration_ms' => $duration,
            ]);
            $trace->save();
        } catch (Throwable $e) {
            RuntimeLogger::warning('agent.trace.finish_failed', [
                'trace_id' => $traceId,
                'exception' => $e::class,
            ]);
        }

        $this->bus?->dispatch([
            'event_type' => 'trace.finished',
            'trace_id' => $traceId,
            'attributes' => ['status' => $status],
            'severity' => 'info',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTraceTimeline(string $traceId, ?int $siteId = null): ?array
    {
        try {
            $trace = SeoAgentTrace::query()->where('trace_id', $traceId)->first();
            if ($trace === null) {
                return null;
            }
            if ($siteId !== null && (int) $trace->site_id !== $siteId) {
                return null; // cross-site forbidden
            }
            $spans = SeoAgentTraceSpan::query()
                ->where('trace_id', $traceId)
                ->orderBy('started_at')
                ->get()
                ->map(static fn (SeoAgentTraceSpan $s): array => [
                    'span_id' => $s->span_id,
                    'parent_span_id' => $s->parent_span_id,
                    'span_type' => $s->span_type,
                    'status' => $s->status,
                    'duration_ms' => $s->duration_ms,
                    'attributes' => $s->attributes,
                    'references' => $s->references_json,
                    'error_code' => $s->error_code,
                    'started_at' => optional($s->started_at)?->toIso8601String(),
                    'finished_at' => optional($s->finished_at)?->toIso8601String(),
                ])
                ->all();

            return [
                'trace_id' => $trace->trace_id,
                'status' => $trace->status,
                'site_ref' => $trace->site_ref,
                'started_at' => optional($trace->started_at)?->toIso8601String(),
                'finished_at' => optional($trace->finished_at)?->toIso8601String(),
                'duration_ms' => $trace->duration_ms,
                'references' => $trace->references_json,
                'version_snapshot' => $trace->version_snapshot,
                'spans' => $spans,
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
