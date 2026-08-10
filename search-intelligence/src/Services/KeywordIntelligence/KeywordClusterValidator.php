<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterCandidate;

/**
 * Validate 1 KeywordClusterCandidate trước khi persist thành SeoKeywordCluster.
 * Không tự sửa candidate — chỉ trả status + lý do để Application layer quyết định flow tiếp theo.
 */
final class KeywordClusterValidator
{
    private const DEFAULT_MAX_CLUSTER_SIZE = 40;

    private const LOW_CONFIDENCE_THRESHOLD = 0.5;

    /**
     * @return array{status: 'valid'|'needs_split'|'needs_review'|'invalid', reasons: list<string>}
     */
    public function validate(KeywordClusterCandidate $candidate): array
    {
        $invalidReasons = $this->invalidReasons($candidate);
        if ($invalidReasons !== []) {
            return ['status' => 'invalid', 'reasons' => $invalidReasons];
        }

        $maxSize = $this->maxClusterSizeConfig();
        if ($maxSize > 0 && count($candidate->keywordIds) > $maxSize) {
            return ['status' => 'needs_split', 'reasons' => ['keyword.cluster_too_large']];
        }

        $reviewReasons = $this->needsReviewReasons($candidate);
        if ($reviewReasons !== []) {
            return ['status' => 'needs_review', 'reasons' => $reviewReasons];
        }

        return ['status' => 'valid', 'reasons' => []];
    }

    /**
     * @return list<string>
     */
    private function invalidReasons(KeywordClusterCandidate $candidate): array
    {
        $reasons = [];

        if ($candidate->keywordIds === []) {
            $reasons[] = 'keyword.cluster_empty';
        }

        if (
            $candidate->primaryKeywordId !== null
            && $candidate->keywordIds !== []
            && ! in_array($candidate->primaryKeywordId, $candidate->keywordIds, true)
        ) {
            $reasons[] = 'keyword.cluster_primary_not_in_members';
        }

        if (trim($candidate->suggestedName) === '') {
            $reasons[] = 'keyword.cluster_missing_name';
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function needsReviewReasons(KeywordClusterCandidate $candidate): array
    {
        $reasons = [];

        if ($candidate->confidence < self::LOW_CONFIDENCE_THRESHOLD) {
            $reasons[] = 'keyword.cluster_low_confidence';
        }

        if ($candidate->existingArticleId !== null) {
            $reasons[] = 'keyword.cluster_existing_article_conflict';
        }

        if ($candidate->intent === null || $candidate->intent === KeywordSearchIntent::Unknown) {
            $reasons[] = 'keyword.cluster_unknown_intent';
        }

        return $reasons;
    }

    private function maxClusterSizeConfig(): int
    {
        if (! function_exists('config')) {
            return self::DEFAULT_MAX_CLUSTER_SIZE;
        }

        try {
            return (int) config('seo-content-ai.keyword_intelligence.clustering.max_cluster_size', self::DEFAULT_MAX_CLUSTER_SIZE);
        } catch (\Throwable) {
            return self::DEFAULT_MAX_CLUSTER_SIZE;
        }
    }
}
