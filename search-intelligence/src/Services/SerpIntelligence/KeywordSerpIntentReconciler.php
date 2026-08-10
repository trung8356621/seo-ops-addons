<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Enums\SerpIntentReconciliationCode;

/**
 * So sánh intent từ cluster/classifier/SERP/manual — không auto-overwrite manual.
 */
final class KeywordSerpIntentReconciler
{
    /**
     * @param  array<string, mixed>  $sources  cluster_intent, classified_intent, serp_intent, manual_intent, field_sources?
     * @return array{
     *   code: SerpIntentReconciliationCode,
     *   effective_intent: string,
     *   manual_locked: bool,
     *   details: array<string, mixed>
     * }
     */
    public function reconcile(array $sources): array
    {
        $manualIntent = $this->normalizeIntent($sources['manual_intent'] ?? null);
        $fieldSources = is_array($sources['field_sources'] ?? null) ? $sources['field_sources'] : [];
        $manualLocked = ($fieldSources['intent'] ?? null) === 'manual' || $manualIntent !== null;

        if ($manualLocked && $manualIntent !== null) {
            return [
                'code' => SerpIntentReconciliationCode::Consistent,
                'effective_intent' => $manualIntent,
                'manual_locked' => true,
                'details' => [
                    'reason' => 'manual_override_wins',
                    'manual_intent' => $manualIntent,
                ],
            ];
        }

        $clusterIntent = $this->normalizeIntent($sources['cluster_intent'] ?? null);
        $classifiedIntent = $this->normalizeIntent($sources['classified_intent'] ?? null);
        $serpIntent = $this->normalizeIntent($sources['serp_intent'] ?? $sources['observed_primary_intent'] ?? null);
        $serpConfidence = (float) ($sources['serp_confidence'] ?? 0.0);

        $minConfidence = $this->configFloat('intent.min_evidence_confidence', 0.45);
        if ($serpIntent === null && $classifiedIntent === null && $clusterIntent === null) {
            return [
                'code' => SerpIntentReconciliationCode::InsufficientEvidence,
                'effective_intent' => KeywordSearchIntent::Unknown->value,
                'manual_locked' => false,
                'details' => ['reason' => 'no_intent_sources'],
            ];
        }

        if ($serpIntent !== null && $serpConfidence < $minConfidence) {
            return [
                'code' => SerpIntentReconciliationCode::InsufficientEvidence,
                'effective_intent' => $classifiedIntent ?? $clusterIntent ?? KeywordSearchIntent::Unknown->value,
                'manual_locked' => false,
                'details' => [
                    'reason' => 'serp_confidence_below_threshold',
                    'serp_confidence' => $serpConfidence,
                    'threshold' => $minConfidence,
                ],
            ];
        }

        $nonEmpty = array_values(array_filter([$clusterIntent, $classifiedIntent, $serpIntent], static fn (?string $v): bool => $v !== null));
        if ($nonEmpty === []) {
            return [
                'code' => SerpIntentReconciliationCode::InsufficientEvidence,
                'effective_intent' => KeywordSearchIntent::Unknown->value,
                'manual_locked' => false,
                'details' => ['reason' => 'empty_after_normalize'],
            ];
        }

        $unique = array_values(array_unique($nonEmpty));
        if (count($unique) === 1) {
            return [
                'code' => SerpIntentReconciliationCode::Consistent,
                'effective_intent' => $unique[0],
                'manual_locked' => false,
                'details' => [
                    'cluster_intent' => $clusterIntent,
                    'classified_intent' => $classifiedIntent,
                    'serp_intent' => $serpIntent,
                ],
            ];
        }

        if ($this->isMixedCompatible($unique)) {
            return [
                'code' => SerpIntentReconciliationCode::Mixed,
                'effective_intent' => KeywordSearchIntent::Mixed->value,
                'manual_locked' => false,
                'details' => [
                    'intents' => $unique,
                    'cluster_intent' => $clusterIntent,
                    'classified_intent' => $classifiedIntent,
                    'serp_intent' => $serpIntent,
                ],
            ];
        }

        $effective = $serpIntent ?? $classifiedIntent ?? $clusterIntent ?? KeywordSearchIntent::Unknown->value;

        return [
            'code' => SerpIntentReconciliationCode::Mismatch,
            'effective_intent' => $effective,
            'manual_locked' => false,
            'details' => [
                'intents' => $unique,
                'cluster_intent' => $clusterIntent,
                'classified_intent' => $classifiedIntent,
                'serp_intent' => $serpIntent,
            ],
        ];
    }

    private function normalizeIntent(mixed $value): ?string
    {
        if ($value instanceof KeywordSearchIntent) {
            return $value->value;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim(mb_strtolower($value, 'UTF-8'));
        if ($trimmed === '') {
            return null;
        }

        $enum = KeywordSearchIntent::tryFrom($trimmed);

        return $enum?->value ?? $trimmed;
    }

    /**
     * @param  list<string>  $intents
     */
    private function isMixedCompatible(array $intents): bool
    {
        $compatibleGroups = $this->configGroups('intent.compatible_mixed_groups', [
            ['informational', 'commercial'],
            ['commercial', 'transactional'],
            ['local', 'commercial'],
        ]);

        sort($intents);

        foreach ($compatibleGroups as $group) {
            sort($group);
            if ($intents === $group) {
                return true;
            }
        }

        return count($intents) >= 2 && count(array_unique($intents)) >= 2;
    }

    /** @return list<list<string>> */
    private function configGroups(string $key, array $default): array
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            $value = config('seo-content-ai.serp_intelligence.'.$key, $default);

            return is_array($value) ? $value : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function configFloat(string $key, float $default): float
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (float) config('seo-content-ai.serp_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
