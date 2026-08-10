<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordRelationshipType;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordRelationship;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;

/**
 * Phát hiện near-duplicate trong toàn workspace mà KHÔNG so sánh O(n²) toàn tập —
 * chỉ so sánh trong cùng bucket (KeywordCandidateBucketer), giới hạn số cặp/keyword.
 */
final class KeywordNearDuplicateDetector
{
    private const DEFAULT_THRESHOLD = 88.0;

    private const DEFAULT_MAX_PAIRS_PER_KEYWORD = 20;

    public function __construct(
        private readonly KeywordCandidateBucketer $bucketer,
        private readonly KeywordNormalizationService $normalizer,
    ) {}

    /**
     * @return list<array{keyword_a_id: int, keyword_b_id: int, score: float, reason_code: string}>
     */
    public function detectCandidates(SeoKeywordWorkspace $workspace, ?int $maxPairsPerKeyword = null): array
    {
        $maxPairs = $maxPairsPerKeyword ?? $this->maxPairsPerKeywordConfig();
        $threshold = $this->thresholdConfig();

        $keywords = SeoKiKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_excluded', false)
            ->where('is_duplicate', false)
            ->orderBy('id')
            ->get();

        if ($keywords->count() < 2) {
            return [];
        }

        $bucketed = $this->bucketer->bucket($keywords, 'near_duplicate');

        /** @var array<int, int> $pairCounts */
        $pairCounts = [];
        /** @var array<string, bool> $seenPairs */
        $seenPairs = [];
        $candidates = [];

        foreach ($bucketed['buckets'] as $bucketKeywords) {
            $count = count($bucketKeywords);

            for ($i = 0; $i < $count; $i++) {
                $keywordA = $bucketKeywords[$i];
                $idA = (int) $keywordA->id;

                if (($pairCounts[$idA] ?? 0) >= $maxPairs) {
                    continue;
                }

                for ($j = $i + 1; $j < $count; $j++) {
                    $keywordB = $bucketKeywords[$j];
                    $idB = (int) $keywordB->id;

                    if (($pairCounts[$idA] ?? 0) >= $maxPairs || ($pairCounts[$idB] ?? 0) >= $maxPairs) {
                        continue;
                    }

                    $pairKey = $idA < $idB ? "{$idA}:{$idB}" : "{$idB}:{$idA}";
                    if (isset($seenPairs[$pairKey])) {
                        continue;
                    }

                    $normalizedA = (string) $keywordA->normalized_keyword;
                    $normalizedB = (string) $keywordB->normalized_keyword;

                    similar_text($normalizedA, $normalizedB, $percent);
                    if ($percent < $threshold) {
                        continue;
                    }

                    $seenPairs[$pairKey] = true;
                    $pairCounts[$idA] = ($pairCounts[$idA] ?? 0) + 1;
                    $pairCounts[$idB] = ($pairCounts[$idB] ?? 0) + 1;

                    $reasonCode = $this->normalizer->isNearDuplicate($normalizedA, $normalizedB)
                        ? 'keyword.near_duplicate_token_match'
                        : 'keyword.near_duplicate_similarity';

                    $candidates[] = [
                        'keyword_a_id' => $idA,
                        'keyword_b_id' => $idB,
                        'score' => round($percent, 2),
                        'reason_code' => $reasonCode,
                    ];
                }
            }
        }

        return $candidates;
    }

    /**
     * Persist danh sách candidate vào SeoKeywordRelationship (type near_duplicate) —
     * bỏ qua nếu model không tồn tại (an toàn khi chạy trước migration) hoặc quan hệ đã có.
     *
     * @param  list<array{keyword_a_id: int, keyword_b_id: int, score: float, reason_code: string}>  $candidates
     */
    public function persistCandidates(SeoKeywordWorkspace $workspace, array $candidates): int
    {
        if (! class_exists(SeoKeywordRelationship::class)) {
            return 0;
        }

        $persisted = 0;

        foreach ($candidates as $candidate) {
            $keywordAId = (int) $candidate['keyword_a_id'];
            $keywordBId = (int) $candidate['keyword_b_id'];

            $exists = SeoKeywordRelationship::query()
                ->where('workspace_id', $workspace->id)
                ->where('relationship_type', KeywordRelationshipType::NearDuplicate->value)
                ->where(function ($query) use ($keywordAId, $keywordBId): void {
                    $query->where(function ($sub) use ($keywordAId, $keywordBId): void {
                        $sub->where('keyword_id', $keywordAId)->where('related_keyword_id', $keywordBId);
                    })->orWhere(function ($sub) use ($keywordAId, $keywordBId): void {
                        $sub->where('keyword_id', $keywordBId)->where('related_keyword_id', $keywordAId);
                    });
                })
                ->exists();

            if ($exists) {
                continue;
            }

            $relationship = new SeoKeywordRelationship([
                'public_ref' => 'pending',
                'workspace_id' => $workspace->id,
                'keyword_id' => $keywordAId,
                'related_keyword_id' => $keywordBId,
                'relationship_type' => KeywordRelationshipType::NearDuplicate->value,
                'confidence' => min(1.0, ((float) $candidate['score']) / 100),
                'metadata' => [
                    'reason_code' => $candidate['reason_code'],
                    'detected_during' => 'near_duplicate_scan',
                ],
            ]);
            $relationship->save();
            $relationship->public_ref = KeywordIntelligencePublicRef::relationship((int) $relationship->id);
            $relationship->save();

            $persisted++;
        }

        return $persisted;
    }

    private function thresholdConfig(): float
    {
        if (! function_exists('config')) {
            return self::DEFAULT_THRESHOLD;
        }

        try {
            return (float) config('seo-content-ai.keyword_intelligence.near_duplicate.threshold', self::DEFAULT_THRESHOLD);
        } catch (\Throwable) {
            return self::DEFAULT_THRESHOLD;
        }
    }

    private function maxPairsPerKeywordConfig(): int
    {
        if (! function_exists('config')) {
            return self::DEFAULT_MAX_PAIRS_PER_KEYWORD;
        }

        try {
            return (int) config('seo-content-ai.keyword_intelligence.near_duplicate.max_candidate_pairs_per_keyword', self::DEFAULT_MAX_PAIRS_PER_KEYWORD);
        } catch (\Throwable) {
            return self::DEFAULT_MAX_PAIRS_PER_KEYWORD;
        }
    }
}
