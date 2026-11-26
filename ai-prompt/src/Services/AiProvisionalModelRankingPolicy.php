<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiProvisionalModelScore;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * Deterministic provisional ranking for unknown (non-curated) models.
 * Capability compatibility is a gate owned by the caller — not a score factor.
 */
final class AiProvisionalModelRankingPolicy
{
    public const WEIGHT_QUALITY = 0.35;

    public const WEIGHT_PRICE = 0.30;

    public const WEIGHT_CONTEXT = 0.20;

    public const WEIGHT_SPEED = 0.15;

    /** Neutral midpoint when a dimension is unknown (never “free” / never “fast”). */
    public const NEUTRAL = 0.50;

    /**
     * @param  list<string>  $capabilities
     */
    public function score(
        SeoAiModel $model,
        AiModelArea $area,
        array $capabilities,
        string $capabilityLabel = 'text.generate',
    ): AiProvisionalModelScore {
        $capsBag = is_array($model->capabilities) ? $model->capabilities : [];
        $meta = is_array($capsBag['provider_metadata'] ?? null) ? $capsBag['provider_metadata'] : [];
        $raw = strtolower((string) $model->raw_model_name);
        $missing = [];

        $quality = $this->qualityDimension($raw, $meta, $missing);
        $price = $this->priceDimension($meta, $missing);
        $context = $this->contextDimension($meta, $missing);
        $speed = $this->speedDimension($raw, $meta, $area, $missing);

        $score = ($quality['value'] ?? self::NEUTRAL) * self::WEIGHT_QUALITY
            + ($price['value'] ?? self::NEUTRAL) * self::WEIGHT_PRICE
            + ($context['value'] ?? self::NEUTRAL) * self::WEIGHT_CONTEXT
            + ($speed['value'] ?? self::NEUTRAL) * self::WEIGHT_SPEED;

        $knownDims = 0;
        foreach ([$quality, $price, $context, $speed] as $dim) {
            if (($dim['source'] ?? '') !== 'unknown') {
                $knownDims++;
            }
        }
        // More unknown dimensions → lower confidence; capability presence is assumed by caller.
        $confidence = 0.35 + (0.15 * $knownDims);
        if ($capabilities !== []) {
            $confidence += 0.10;
        }
        $confidence = min(0.85, $confidence);

        $reason = $knownDims === 0
            ? 'provisional_neutral_missing_metadata'
            : 'provisional_weighted_estimate';

        return new AiProvisionalModelScore(
            score: $score,
            confidence: $confidence,
            scoreSource: AiProvisionalModelScore::SOURCE_ESTIMATED,
            capability: $capabilityLabel,
            reason: $reason,
            missingMetadata: $missing,
            dimensions: [
                'quality' => $quality,
                'price' => $price,
                'context' => $context,
                'speed' => $speed,
            ],
        );
    }

    /**
     * Stable tie-break: higher score first, then higher confidence, then lower model id.
     *
     * @param  list<array{model: SeoAiModel, score: AiProvisionalModelScore}>  $rows
     * @return list<array{model: SeoAiModel, score: AiProvisionalModelScore}>
     */
    public function sortStable(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $cmp = $b['score']->score <=> $a['score']->score;
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $b['score']->confidence <=> $a['score']->confidence;
            if ($cmp !== 0) {
                return $cmp;
            }

            return (int) $a['model']->id <=> (int) $b['model']->id;
        });

        return array_values($rows);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $missing
     * @return array{value: float|null, source: string, note?: string}
     */
    private function qualityDimension(string $raw, array $meta, array &$missing): array
    {
        if (isset($meta['quality_score']) && is_numeric($meta['quality_score'])) {
            return [
                'value' => max(0.0, min(1.0, (float) $meta['quality_score'])),
                'source' => 'provider_declared',
            ];
        }
        // Do not treat parameter count as proof of quality.
        if (isset($meta['parameters']) || preg_match('/(\d+)\s*b\b/', $raw) === 1) {
            $missing[] = 'quality_measured';

            return [
                'value' => self::NEUTRAL,
                'source' => 'unknown',
                'note' => 'param_count_ignored',
            ];
        }
        $missing[] = 'quality';

        return ['value' => self::NEUTRAL, 'source' => 'unknown'];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $missing
     * @return array{value: float|null, source: string, note?: string}
     */
    private function priceDimension(array $meta, array &$missing): array
    {
        $prompt = $meta['pricing']['prompt'] ?? $meta['prompt_price'] ?? $meta['input_price'] ?? null;
        $completion = $meta['pricing']['completion'] ?? $meta['completion_price'] ?? $meta['output_price'] ?? null;
        if (is_numeric($prompt) || is_numeric($completion)) {
            $avg = ((float) ($prompt ?? $completion) + (float) ($completion ?? $prompt)) / 2.0;
            // Lower price → higher score. Unknown is NEVER treated as free.
            $value = $avg <= 0.0 ? 0.55 : max(0.05, min(0.95, 1.0 / (1.0 + log1p($avg * 1000))));

            return [
                'value' => $value,
                'source' => 'provider_declared',
                'note' => 'unknown_price_is_not_free',
            ];
        }
        $missing[] = 'price';

        return [
            'value' => self::NEUTRAL,
            'source' => 'unknown',
            'note' => 'unknown_price_is_not_free',
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $missing
     * @return array{value: float|null, source: string, note?: string}
     */
    private function contextDimension(array $meta, array &$missing): array
    {
        $ctx = $meta['context_length'] ?? $meta['context_window'] ?? $meta['max_context'] ?? null;
        if (is_numeric($ctx) && (int) $ctx > 0) {
            $n = (int) $ctx;
            $value = match (true) {
                $n >= 200_000 => 0.95,
                $n >= 128_000 => 0.85,
                $n >= 32_000 => 0.70,
                $n >= 8_000 => 0.55,
                default => 0.40,
            };

            return ['value' => $value, 'source' => 'provider_declared'];
        }
        $missing[] = 'context';

        return ['value' => self::NEUTRAL, 'source' => 'unknown'];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $missing
     * @return array{value: float|null, source: string, note?: string}
     */
    private function speedDimension(string $raw, array $meta, AiModelArea $area, array &$missing): array
    {
        if (isset($meta['latency_ms']) && is_numeric($meta['latency_ms'])) {
            $ms = (float) $meta['latency_ms'];
            $value = max(0.05, min(0.95, 1.0 / (1.0 + log1p($ms / 100.0))));

            return ['value' => $value, 'source' => 'measured'];
        }
        // Name hints only when latency unknown — never invent “fast”.
        if ($area === AiModelArea::TextFast
            && (str_contains($raw, 'flash') || str_contains($raw, 'nano') || str_contains($raw, 'mini') || str_contains($raw, 'lightning'))) {
            $missing[] = 'latency_measured';

            return [
                'value' => 0.62,
                'source' => 'inferred',
                'note' => 'name_hint_only',
            ];
        }
        $missing[] = 'latency';

        return [
            'value' => self::NEUTRAL,
            'source' => 'unknown',
            'note' => 'unknown_latency_is_not_fast',
        ];
    }
}
