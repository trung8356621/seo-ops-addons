<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticHttpException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInputHasher;

/**
 * Focused semantic neighbor search among unassigned keywords.
 *
 * Does not persist Groups, does not call Topic apply.
 * Acceptance is owned by the Python search response (`accepted` flag).
 */
final class KeywordGroupSemanticSearchService
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_CANDIDATES = 1000;

    public function __construct(
        private readonly SemanticAnalyticsClient $client,
        private readonly TopicGroupingInputHasher $hasher,
        private readonly KeywordGroupReadModel $readModel,
        private readonly KeywordGroupManualService $manualService,
    ) {}

    /**
     * @param  list<string>|null  $languageVariants
     * @return list<array{keyword_id: int, phrase: string, similarity_score: float, accepted: bool}>
     */
    public function suggestUnassigned(
        int $siteId,
        string $query,
        ?array $languageVariants = null,
        int $limit = self::DEFAULT_LIMIT,
        bool $softFail = true,
    ): array {
        $query = trim($query);
        $limit = max(1, min(50, $limit));
        if ($siteId <= 0 || $query === '') {
            return [];
        }

        $candidates = $this->readModel->unassignedCandidatesForSemanticSearch(
            $siteId,
            $query,
            $languageVariants,
            self::MAX_CANDIDATES,
        );
        if ($candidates === []) {
            return [];
        }

        $payload = [];
        foreach ($candidates as $row) {
            $ref = (string) $row['keyword_id'];
            $text = $this->hasher->normalizeText($row['phrase']);
            if ($ref === '' || $text === '') {
                continue;
            }
            $payload[] = ['ref' => $ref, 'text' => $text];
        }
        if ($payload === []) {
            return [];
        }

        try {
            $response = $this->client->postJson('/v1/keyword-groups/search', [
                'scope_ref' => (string) $siteId,
                'query' => $this->hasher->normalizeText($query),
                'language' => $this->normalizeLanguage($languageVariants),
                'keywords' => $payload,
                'limit' => $limit,
            ], 'keyword-group-search-'.$siteId);
        } catch (SemanticHttpException $e) {
            if ($softFail) {
                return [];
            }

            throw $e;
        }

        return $this->mapHits($response, $limit);
    }

    /**
     * After a successful rename: query Python, append only accepted unassigned matches.
     *
     * @param  list<string>|null  $languageVariants
     * @return array{appended_ids: list<int>, semantic_failed: bool}
     */
    public function appendAcceptedMatchesAfterRename(
        int $siteId,
        int $groupId,
        string $query,
        ?array $languageVariants = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        if ($siteId <= 0 || $groupId <= 0 || trim($query) === '') {
            return ['appended_ids' => [], 'semantic_failed' => false];
        }

        try {
            $hits = $this->suggestUnassigned(
                $siteId,
                $query,
                $languageVariants,
                $limit,
                softFail: false,
            );
        } catch (SemanticHttpException) {
            return ['appended_ids' => [], 'semantic_failed' => true];
        }

        $acceptedIds = [];
        foreach ($hits as $hit) {
            if (($hit['accepted'] ?? false) !== true) {
                continue;
            }
            $keywordId = (int) ($hit['keyword_id'] ?? 0);
            if ($keywordId > 0) {
                $acceptedIds[] = $keywordId;
            }
        }

        if ($acceptedIds === []) {
            return ['appended_ids' => [], 'semantic_failed' => false];
        }

        $appended = $this->manualService->appendUnassignedKeywords($siteId, $groupId, $acceptedIds);

        return ['appended_ids' => $appended, 'semantic_failed' => false];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array{keyword_id: int, phrase: string, similarity_score: float, accepted: bool}>
     */
    private function mapHits(array $response, int $limit): array
    {
        $hits = $response['hits'] ?? null;
        if (! is_array($hits)) {
            return [];
        }

        $out = [];
        foreach ($hits as $hit) {
            if (! is_array($hit)) {
                continue;
            }
            $keywordId = (int) ($hit['ref'] ?? 0);
            $phrase = trim((string) ($hit['text'] ?? ''));
            if ($keywordId <= 0 || $phrase === '') {
                continue;
            }
            $out[] = [
                'keyword_id' => $keywordId,
                'phrase' => $phrase,
                'similarity_score' => (float) ($hit['similarity_score'] ?? 0),
                // Missing accepted → reject (never treat top-K as auto-append).
                'accepted' => ($hit['accepted'] ?? false) === true,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>|null  $languageVariants
     */
    private function normalizeLanguage(?array $languageVariants): ?string
    {
        if ($languageVariants === null || $languageVariants === []) {
            return null;
        }

        return mb_substr(trim((string) $languageVariants[0]), 0, 32) ?: null;
    }
}
