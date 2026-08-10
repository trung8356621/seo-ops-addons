<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordArticleMappingType;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordArticleMapping;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;

/**
 * Resolve Content Project item action from cluster evidence.
 * Distinguishes suggested_content_type (SEO page shape) vs item type (write_new/rewrite/improve).
 * Never invent improve without description evidence.
 */
final class KeywordClusterContentActionResolver
{
    /**
     * @return array{action: string, reason_codes: list<string>, article_ref: string|null}
     */
    public function resolve(SeoKeywordCluster $cluster): array
    {
        $status = $cluster->status instanceof \BackedEnum
            ? $cluster->status->value
            : (string) $cluster->status;

        if ($status === KeywordClusterStatus::Excluded->value) {
            return $this->result('blocked', ['cluster_excluded'], null);
        }

        if ($status === KeywordClusterStatus::Converted->value) {
            $ref = trim((string) ($cluster->target_article_ref ?? ''));

            return $this->result('covered', ['cluster_already_converted'], $ref !== '' ? $ref : null);
        }

        if ($status !== KeywordClusterStatus::Approved->value) {
            return $this->result('blocked', ['cluster_not_approved'], null);
        }

        $targetRef = trim((string) ($cluster->target_article_ref ?? ''));
        $hasTarget = $targetRef !== '';
        $suggestedPageType = trim((string) ($cluster->suggested_content_type ?? ''));
        $metadata = (array) ($cluster->metadata ?? []);
        // Item action evidence — NOT the SEO page type (landing_page ≠ rewrite).
        $evidenceAction = trim((string) ($metadata['content_project_action'] ?? $metadata['reviewed_action'] ?? ''));

        if (! $hasTarget) {
            if ($this->hasHighConfidenceExistingMapping($cluster)) {
                return $this->result('covered', ['high_confidence_existing_mapping'], null);
            }

            if ((int) ($cluster->primary_keyword_id ?? 0) <= 0 && trim((string) ($cluster->name ?? '')) === '') {
                return $this->result('blocked', ['missing_primary_keyword'], null);
            }

            return $this->result('write_new', ['no_existing_target'], null);
        }

        $wantsRewrite = $evidenceAction === 'rewrite' || $suggestedPageType === 'rewrite';
        if ($wantsRewrite) {
            $reasons = ['existing_target', 'rewrite_evidence'];
            if ($this->hasApprovedSerpEvidence($metadata)) {
                $reasons[] = 'serp_evidence_approved';
            }

            return $this->result('rewrite', $reasons, $targetRef);
        }

        $wantsImprove = $evidenceAction === 'improve' || $suggestedPageType === 'improve';
        if ($wantsImprove) {
            if ($this->hasImproveEvidence($cluster, $metadata)) {
                $reasons = ['existing_target', 'improve_evidence'];
                if ($this->hasApprovedSerpEvidence($metadata)) {
                    $reasons[] = 'serp_evidence_approved';
                }

                return $this->result('improve', $reasons, $targetRef);
            }

            return $this->result('needs_review', [
                'improve_description_required',
                KeywordIntelligenceActionCodes::CONVERSION_IMPROVE_DESCRIPTION_REQUIRED,
            ], $targetRef);
        }

        // Target exists but no explicit rewrite/improve evidence → covered if high confidence, else review.
        $confidence = (float) ($metadata['mapping_confidence'] ?? 0.0);
        if ($confidence >= 0.75 || ($metadata['mapping_status'] ?? null) === 'approved') {
            return $this->result('covered', ['high_confidence_existing_mapping'], $targetRef);
        }

        return $this->result('needs_review', ['target_without_explicit_action'], $targetRef);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function hasImproveEvidence(SeoKeywordCluster $cluster, array $metadata): bool
    {
        if (trim((string) ($cluster->suggested_description ?? '')) !== '') {
            return true;
        }

        foreach (['improve_description', 'improve_evidence', 'content_gap_notes'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        if ($this->hasApprovedSerpEvidence($metadata) && ! empty($metadata['serp_content_gaps'])) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function hasApprovedSerpEvidence(array $metadata): bool
    {
        $status = (string) ($metadata['serp_evidence_status'] ?? '');

        return $status === 'approved';
    }

    private function hasHighConfidenceExistingMapping(SeoKeywordCluster $cluster): bool
    {
        if ($cluster->primary_keyword_id === null) {
            return false;
        }

        if (! class_exists(SeoKeywordArticleMapping::class)) {
            return false;
        }

        try {
            return SeoKeywordArticleMapping::query()
                ->where('keyword_id', $cluster->primary_keyword_id)
                ->where('mapping_type', KeywordArticleMappingType::CurrentContent->value)
                ->where('confidence', 'high')
                ->exists();
        } catch (\Throwable) {
            // Pure PHPUnit / no DB — do not treat as covered.
            return false;
        }
    }

    /**
     * @param  list<string>  $reasonCodes
     * @return array{action: string, reason_codes: list<string>, article_ref: string|null}
     */
    private function result(string $action, array $reasonCodes, ?string $articleRef): array
    {
        return ['action' => $action, 'reason_codes' => $reasonCodes, 'article_ref' => $articleRef];
    }
}
