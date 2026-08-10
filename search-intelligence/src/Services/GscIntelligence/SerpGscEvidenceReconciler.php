<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSerpGscMismatchType;

/**
 * Reconcile SERP vs GSC evidence — suggestions only (serp_gsc_mismatch).
 */
final class SerpGscEvidenceReconciler
{
    public const ALGORITHM_VERSION = '1.0.0';

    /**
     * @param  array<string, mixed>  $serpEvidence  observed_intent, position?, in_top_results?
     * @param  array<string, mixed>  $gscMetrics  clicks, impressions, position, ctr?
     * @return list<array<string, mixed>>
     */
    public function reconcile(array $serpEvidence, array $gscMetrics, array $context = []): array
    {
        $suggestions = [];
        $normalizedQuery = (string) ($context['normalized_query'] ?? '');

        $gscImpressions = (int) ($gscMetrics['impressions'] ?? 0);
        $serpInTop = (bool) ($serpEvidence['in_top_results'] ?? $serpEvidence['in_top_10'] ?? false);
        $serpPosition = isset($serpEvidence['position']) ? (float) $serpEvidence['position'] : null;
        $gscPosition = isset($gscMetrics['position']) ? (float) $gscMetrics['position'] : null;

        if ($gscImpressions > 0 && ! $serpInTop) {
            $suggestions[] = $this->suggestion(
                GscSerpGscMismatchType::ImpressionWithoutSerpPresence,
                $normalizedQuery,
                ['gsc_impressions' => $gscImpressions, 'serp_in_top' => false],
            );
        }

        if ($serpInTop && $gscImpressions === 0) {
            $suggestions[] = $this->suggestion(
                GscSerpGscMismatchType::SerpPresenceWithoutImpression,
                $normalizedQuery,
                ['serp_position' => $serpPosition],
            );
        }

        if ($serpPosition !== null && $gscPosition !== null && abs($serpPosition - $gscPosition) >= 5.0) {
            $suggestions[] = $this->suggestion(
                GscSerpGscMismatchType::PositionMismatch,
                $normalizedQuery,
                [
                    'serp_position' => $serpPosition,
                    'gsc_position' => $gscPosition,
                    'delta' => round(abs($serpPosition - $gscPosition), 2),
                ],
            );
        }

        $serpIntent = trim((string) ($serpEvidence['observed_intent'] ?? $serpEvidence['observed_primary_intent'] ?? ''));
        $gscIntentHint = trim((string) ($context['keyword_intent'] ?? ''));
        if ($serpIntent !== '' && $gscIntentHint !== '' && $serpIntent !== $gscIntentHint) {
            $suggestions[] = $this->suggestion(
                GscSerpGscMismatchType::IntentMismatch,
                $normalizedQuery,
                ['serp_intent' => $serpIntent, 'keyword_intent' => $gscIntentHint],
            );
        }

        $serpPageType = trim((string) ($serpEvidence['dominant_page_type'] ?? ''));
        $mappedPageType = trim((string) ($context['mapped_page_type'] ?? ''));
        if ($serpPageType !== '' && $mappedPageType !== '' && $serpPageType !== $mappedPageType) {
            $suggestions[] = $this->suggestion(
                GscSerpGscMismatchType::PageTypeMismatch,
                $normalizedQuery,
                ['serp_page_type' => $serpPageType, 'mapped_page_type' => $mappedPageType],
            );
        }

        return $suggestions;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function suggestion(GscSerpGscMismatchType $type, string $normalizedQuery, array $evidence): array
    {
        return [
            'code' => 'serp_gsc_mismatch',
            'mismatch_type' => $type->value,
            'normalized_query' => $normalizedQuery,
            'evidence' => $evidence,
            'action' => 'review_only',
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];
    }
}
