<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

/**
 * Merge approved SERP evidence vào conversion preview item.
 * Không đụng gallery description field; không mutate Content Project đã tạo.
 */
final class SerpEvidenceContentProjectPreviewAdapter
{
    /**
     * @param  array<string, mixed>  $previewItem
     * @param  array<string, mixed>|null  $serpEvidence
     * @return array<string, mixed>
     */
    public function apply(array $previewItem, ?array $serpEvidence): array
    {
        if ($serpEvidence === null || $serpEvidence === []) {
            return $previewItem;
        }

        $previewItem['serp_evidence_status'] = $serpEvidence['status'] ?? $serpEvidence['serp_evidence_status'] ?? null;
        $previewItem['serp_snapshot_age'] = $serpEvidence['snapshot_age'] ?? $serpEvidence['serp_snapshot_age'] ?? null;
        $previewItem['observed_intent'] = $serpEvidence['observed_intent']
            ?? $serpEvidence['observed_primary_intent']
            ?? null;
        $previewItem['dominant_page_type'] = $serpEvidence['dominant_page_type']
            ?? (($serpEvidence['dominant_page_types'][0] ?? null));
        $previewItem['content_gaps'] = $serpEvidence['content_gaps'] ?? [];
        $previewItem['recommended_action'] = $serpEvidence['recommended_action'] ?? null;
        $previewItem['evidence_confidence'] = $serpEvidence['confidence']
            ?? $serpEvidence['evidence_confidence']
            ?? null;

        $previewItem['serp_evidence'] = [
            'status' => $previewItem['serp_evidence_status'],
            'snapshot_age' => $previewItem['serp_snapshot_age'],
            'observed_intent' => $previewItem['observed_intent'],
            'observed_primary_intent' => $previewItem['observed_intent'],
            'dominant_page_type' => $previewItem['dominant_page_type'],
            'dominant_page_types' => $serpEvidence['dominant_page_types'] ?? array_values(array_filter([
                $previewItem['dominant_page_type'],
            ])),
            'content_gaps' => $previewItem['content_gaps'],
            'recommended_action' => $previewItem['recommended_action'],
            'confidence' => $previewItem['evidence_confidence'],
            'snapshot_refs' => $serpEvidence['snapshot_refs'] ?? [],
            'version' => $serpEvidence['version'] ?? SerpIntentEvidenceService::INTENT_EVIDENCE_VERSION,
        ];

        return $previewItem;
    }
}
