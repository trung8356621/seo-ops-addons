<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

/**
 * Versioned pricing registry — never fabricate cost when unknown.
 */
final class AgentCostUsageTracker
{
    /**
     * @param  array<string, array{input_per_1k?: float, output_per_1k?: float}>  $pricing
     */
    public function __construct(
        private readonly array $pricing = [],
        private readonly ?AgentMetricRecorder $metrics = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $usage
     * @return array{input_tokens: ?int, output_tokens: ?int, cached_tokens: ?int, cost_estimate: ?float, cost_unknown: bool, provider: ?string, model: ?string, latency_ms: ?int}
     */
    public function track(
        ?array $usage,
        ?string $provider,
        ?string $model,
        ?int $latencyMs,
        ?string $traceId = null,
        ?int $siteId = null,
    ): array {
        $input = isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : (isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null);
        $output = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : (isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null);
        $cached = isset($usage['cached_tokens']) ? (int) $usage['cached_tokens'] : null;

        $cost = null;
        $unknown = true;
        $key = ($provider ?? '').':'.($model ?? '');
        if ($key !== ':' && isset($this->pricing[$key]) && $input !== null && $output !== null) {
            $p = $this->pricing[$key];
            $cost = (($input / 1000) * (float) ($p['input_per_1k'] ?? 0))
                + (($output / 1000) * (float) ($p['output_per_1k'] ?? 0));
            $unknown = false;
        }

        if ($input !== null) {
            $this->metrics?->record('cost.tokens_input', (float) $input, [
                'provider' => (string) $provider,
                'model' => (string) $model,
            ], $traceId, $siteId);
        }
        if ($output !== null) {
            $this->metrics?->record('cost.tokens_output', (float) $output, [
                'provider' => (string) $provider,
                'model' => (string) $model,
            ], $traceId, $siteId);
        }
        if ($cost !== null) {
            $this->metrics?->record('cost.estimate', $cost, [
                'provider' => (string) $provider,
                'model' => (string) $model,
            ], $traceId, $siteId);
        } else {
            $this->metrics?->record('cost.unknown', 1, [
                'provider' => (string) ($provider ?? 'unknown'),
                'model' => (string) ($model ?? 'unknown'),
            ], $traceId, $siteId);
        }

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cached_tokens' => $cached,
            'cost_estimate' => $cost,
            'cost_unknown' => $unknown,
            'provider' => $provider,
            'model' => $model,
            'latency_ms' => $latencyMs,
        ];
    }
}
