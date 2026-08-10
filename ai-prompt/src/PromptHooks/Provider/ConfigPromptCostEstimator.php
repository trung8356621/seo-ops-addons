<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Provider;

/**
 * Catalog-only estimator — không hardcode giá production; trả null trừ khi config có rate.
 */
final class ConfigPromptCostEstimator implements PromptCostEstimator
{
    public function estimate(array $usage): ?float
    {
        $rates = config('seo-content-ai.prompt_hooks.cost_rates', []);
        if (! is_array($rates) || $rates === []) {
            return null;
        }

        $provider = strtolower((string) ($usage['provider'] ?? 'default'));
        $model = (string) ($usage['model'] ?? '');
        $rate = $rates[$provider][$model] ?? $rates[$provider]['*'] ?? $rates['*'] ?? null;
        if (! is_array($rate)) {
            return null;
        }

        $in = max(0, (int) ($usage['input_tokens'] ?? 0));
        $out = max(0, (int) ($usage['output_tokens'] ?? 0));
        $inPerM = (float) ($rate['input_per_1m'] ?? 0);
        $outPerM = (float) ($rate['output_per_1m'] ?? 0);
        if ($inPerM <= 0 && $outPerM <= 0) {
            return null;
        }

        return round(($in / 1_000_000) * $inPerM + ($out / 1_000_000) * $outPerM, 6);
    }
}
