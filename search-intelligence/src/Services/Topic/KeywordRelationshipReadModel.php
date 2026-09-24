<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscQueryMappingType;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscQueryMapping;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSiteKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\SkipKeywordFromMcpService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordRelationship;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\RelationshipListSlice;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;

/**
 * Canonical one-keyword relationship read model (Keyword MCP type-2).
 *
 * On-demand only — never writes seo_mcp_source_snapshots.
 * Includes A+B relations only (no heuristic semantic neighbors).
 *
 * Explicit inspection of an McpExcluded keyword remains available (cleanup/debug)
 * with mcp_excluded=true on core. Type-1 landscape metrics stay MCP-eligible only.
 */
final class KeywordRelationshipReadModel
{
    /** Deterministic GSC mapping types only (exclude near/cluster heuristics). */
    private const GSC_CANONICAL_TYPES = [
        GscQueryMappingType::ExactKeyword->value,
        GscQueryMappingType::NormalizedKeyword->value,
        GscQueryMappingType::Manual->value,
    ];

    public function __construct(
        private readonly TopicMembershipQuery $membership,
        private readonly KeywordMetaRepository $keywordMeta,
        private readonly KeywordLandscapeGateway $landscape,
        private readonly ?SkipKeywordFromMcpService $mcpSkip = null,
        private readonly ?TopicMcpExclusionService $topicMcp = null,
    ) {}

    private function mcpSkip(): SkipKeywordFromMcpService
    {
        return $this->mcpSkip ?? app(SkipKeywordFromMcpService::class);
    }

    private function topicMcp(): TopicMcpExclusionService
    {
        return $this->topicMcp ?? app(TopicMcpExclusionService::class);
    }

    public function relationship(int $siteId, int $keywordId): ?KeywordRelationship
    {
        if ($siteId <= 0 || $keywordId <= 0) {
            return null;
        }

        if (! $this->keywordVisibleOnSite($siteId, $keywordId)) {
            return null;
        }

        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword) {
            return null;
        }

        $siteClass = $this->loadSiteClassification($siteId, $keywordId);
        $membershipRow = $this->loadTopicMembership($siteId, $keywordId);
        $isMcpExcluded = $this->mcpSkip()->isSkipped($keywordId);
        $core = $this->buildKeywordCore($keyword, $siteClass, $membershipRow, $isMcpExcluded);
        $topic = $this->membership->topicForKeyword($siteId, $keywordId);
        $topics = [];
        $sourceUpdatedAt = null;
        if ($topic !== null) {
            $landscapeTopic = $this->landscape->findTopic($siteId, (int) $topic->id, true);
            $topicMcpExcluded = $this->topicMcp()->isExcluded($siteId, (int) $topic->id);
            $topics[] = [
                'topic_ref' => 'topic:'.(int) $topic->id,
                'id' => (int) $topic->id,
                'name' => trim((string) $topic->name),
                'status' => (string) ($topic->status ?? ''),
                'mcp' => $landscapeTopic?->mcp,
                'dna_count' => $landscapeTopic?->dnaCount,
                'coverage' => $landscapeTopic?->coverage,
                'article_count' => $landscapeTopic?->articleCount,
                'mcp_excluded' => $topicMcpExcluded,
            ];
            $sourceUpdatedAt = $topic->updated_at?->toIso8601String();
        }

        $focusArticles = $this->loadFocusArticles($siteId, $keywordId);
        $dna = $this->loadTopicDna($siteId, $topic !== null ? (int) $topic->id : 0);
        $related = $this->loadRelatedKeywords($siteId, $keywordId, $topic !== null ? (int) $topic->id : 0);
        $links = $this->loadInternalLinks($siteId, $focusArticles);
        $gsc = $this->loadGsc($siteId, $keywordId);
        $planning = $this->loadPlanning($siteId, (string) $keyword->phrase);
        $issues = $this->detectRelationIssues($topics, $focusArticles);

        $available = ['core', 'topics', 'focus_articles', 'dna', 'related_keywords'];
        if ($links['available'] === true) {
            $available[] = 'internal_links';
        }
        if ($gsc['available'] === true) {
            $available[] = 'gsc';
        }
        if ($planning['available'] === true) {
            $available[] = 'planning';
        }

        return new KeywordRelationship(
            siteId: $siteId,
            keyword: $core,
            topics: $topics,
            focusArticles: $focusArticles,
            dna: ['topic_dna' => $dna],
            relatedKeywords: $related,
            internalLinks: $links,
            gsc: $gsc,
            planning: $planning,
            relationIssues: $issues,
            availableSections: $available,
            generatedAt: now()->toIso8601String(),
            sourceUpdatedAt: $sourceUpdatedAt,
        );
    }

    private function keywordVisibleOnSite(int $siteId, int $keywordId): bool
    {
        if ($this->tableReady('seo_topic_keywords')) {
            $inTopic = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('keyword_id', $keywordId)
                ->exists();
            if ($inTopic) {
                return true;
            }
        }

        if ($this->tableReady('seo_site_keywords')) {
            $inSite = SeoSiteKeyword::query()
                ->where('site_id', $siteId)
                ->where('keyword_id', $keywordId)
                ->exists();
            if ($inSite) {
                return true;
            }
        }

        return Keyword::query()
            ->forSite($siteId)
            ->whereKey($keywordId)
            ->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSiteClassification(int $siteId, int $keywordId): ?array
    {
        if (! $this->tableReady('seo_site_keywords')) {
            return null;
        }

        $row = SeoSiteKeyword::query()
            ->where('site_id', $siteId)
            ->where('keyword_id', $keywordId)
            ->first();

        return $row instanceof SeoSiteKeyword ? $row->toArray() : null;
    }

    private function loadTopicMembership(int $siteId, int $keywordId): ?SeoTopicKeyword
    {
        if (! $this->tableReady('seo_topic_keywords')) {
            return null;
        }

        $row = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('keyword_id', $keywordId)
            ->first();

        return $row instanceof SeoTopicKeyword ? $row : null;
    }

    /**
     * @param  array<string, mixed>|null  $siteClass
     * @return array<string, mixed>
     */
    private function buildKeywordCore(
        Keyword $keyword,
        ?array $siteClass,
        ?SeoTopicKeyword $membership,
        bool $mcpExcluded = false,
    ): array {
        $id = (int) $keyword->id;

        $classification = null;
        if (isset($siteClass['seo_intent']) && is_string($siteClass['seo_intent']) && $siteClass['seo_intent'] !== '') {
            $classification = $siteClass['seo_intent'];
        } elseif (isset($siteClass['phrase_kind']) && is_string($siteClass['phrase_kind']) && $siteClass['phrase_kind'] !== '') {
            $classification = $siteClass['phrase_kind'];
        } elseif (($keyword->type ?? '') !== '') {
            $classification = (string) $keyword->type;
        }

        return [
            'ref' => 'keyword:'.$id,
            'id' => $id,
            'phrase' => (string) $keyword->phrase,
            'classification' => $classification,
            'type' => (string) ($keyword->type ?? ''),
            'source' => $siteClass['source'] ?? ($keyword->source !== null ? (string) $keyword->source : null),
            'is_seo_keyword' => isset($siteClass['is_seo_keyword']) ? (bool) $siteClass['is_seo_keyword'] : null,
            'is_anchor_candidate' => isset($siteClass['is_anchor_candidate']) ? (bool) $siteClass['is_anchor_candidate'] : null,
            'is_ambiguous' => isset($siteClass['is_ambiguous']) ? (bool) $siteClass['is_ambiguous'] : null,
            'keyword_score' => isset($siteClass['keyword_score']) ? (float) $siteClass['keyword_score'] : null,
            'review_band' => isset($siteClass['review_state']) ? (string) $siteClass['review_state'] : null,
            'review_status' => $keyword->review_status !== null ? (string) $keyword->review_status : null,
            // Distinct lock axes — never conflate Keyword.source_locked with membership is_locked.
            'source_locked' => (bool) ($keyword->source_locked ?? false),
            'membership_locked' => $membership instanceof SeoTopicKeyword
                ? (bool) ($membership->is_locked ?? false)
                : null,
            'mcp_excluded' => $mcpExcluded,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadFocusArticles(int $siteId, int $keywordId): array
    {
        $articleId = $this->keywordMeta->getMainArticleIdForSite($keywordId, $siteId);
        if ($articleId === null || $articleId <= 0) {
            return [];
        }

        $article = SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereKey($articleId)
            ->first(['id', 'site_id', 'title', 'status', 'slug', 'published_at', 'updated_at']);

        if (! $article instanceof SeoArticle) {
            return [];
        }

        return [[
            'article_ref' => 'article:'.(int) $article->id,
            'article_id' => (int) $article->id,
            'title' => (string) ($article->title ?? ''),
            'status' => (string) ($article->status ?? ''),
            'slug' => (string) ($article->slug ?? ''),
            'published_at' => $article->published_at?->toIso8601String(),
            'updated_at' => $article->updated_at?->toIso8601String(),
        ]];
    }

    /**
     * @return list<array{phrase: string, weight: int}>
     */
    private function loadTopicDna(int $siteId, int $topicId): array
    {
        if ($siteId <= 0 || $topicId <= 0 || ! $this->tableReady('seo_topic_keyword_dna')) {
            return [];
        }

        $rows = SeoTopicKeywordDna::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->orderBy('value')
            ->get(['keyword_id', 'value']);

        $keywordIds = [];
        foreach ($rows as $row) {
            $kid = (int) ($row->keyword_id ?? 0);
            if ($kid > 0) {
                $keywordIds[] = $kid;
            }
        }
        $skipped = $this->mcpSkip()->skippedKeywordIdMap(array_values(array_unique($keywordIds)));

        $weights = [];
        foreach ($rows as $row) {
            $kid = (int) ($row->keyword_id ?? 0);
            if ($kid > 0 && isset($skipped[$kid])) {
                continue;
            }
            $phrase = trim((string) ($row->value ?? ''));
            if ($phrase === '') {
                continue;
            }
            $key = mb_strtolower($phrase, 'UTF-8');
            $weights[$key] = [
                'phrase' => $phrase,
                'weight' => (int) (($weights[$key]['weight'] ?? 0) + 1),
            ];
        }

        $list = array_values($weights);
        usort($list, static function (array $a, array $b): int {
            $byWeight = $b['weight'] <=> $a['weight'];
            if ($byWeight !== 0) {
                return $byWeight;
            }

            return strcmp(mb_strtolower($a['phrase'], 'UTF-8'), mb_strtolower($b['phrase'], 'UTF-8'));
        });

        return array_slice($list, 0, KeywordRelationship::DNA_LIMIT);
    }

    private function loadRelatedKeywords(int $siteId, int $keywordId, int $topicId): RelationshipListSlice
    {
        if ($topicId <= 0) {
            return RelationshipListSlice::fromAll([], KeywordRelationship::RELATED_LIMIT);
        }

        $siblingIds = $this->membership->keywordIdsForTopic($siteId, $topicId);
        $siblingIds = array_values(array_filter(
            $siblingIds,
            static fn (int $id): bool => $id > 0 && $id !== $keywordId,
        ));
        $skipped = $this->mcpSkip()->skippedKeywordIdMap($siblingIds);
        $siblingIds = array_values(array_filter(
            $siblingIds,
            static fn (int $id): bool => ! isset($skipped[$id]),
        ));
        sort($siblingIds);

        if ($siblingIds === []) {
            return RelationshipListSlice::fromAll([], KeywordRelationship::RELATED_LIMIT);
        }

        $phrases = Keyword::query()
            ->whereIn('id', $siblingIds)
            ->orderBy('phrase')
            ->orderBy('id')
            ->get(['id', 'phrase']);

        $items = [];
        foreach ($phrases as $row) {
            $items[] = [
                'keyword_ref' => 'keyword:'.(int) $row->id,
                'id' => (int) $row->id,
                'phrase' => (string) $row->phrase,
                'relation_type' => 'same_topic',
            ];
        }

        return RelationshipListSlice::fromAll($items, KeywordRelationship::RELATED_LIMIT);
    }

    /**
     * Focus-article internal-link neighborhood (synced seo_link_maps only).
     *
     * inbound:  link_type=internal AND target_article_id = focus
     * outbound: link_type=internal AND source_article_id = focus
     * Both directions site-scoped. No WP crawl. No fabricated edges without focus.
     *
     * @param  list<array<string, mixed>>  $focusArticles
     * @return array{available: bool, inbound: array<string, mixed>, outbound: array<string, mixed>}
     */
    private function loadInternalLinks(int $siteId, array $focusArticles): array
    {
        $emptySlice = RelationshipListSlice::fromAll([], KeywordRelationship::LINK_LIMIT)->toArray();

        if (! $this->tableReady('seo_link_maps')) {
            return [
                'available' => false,
                'inbound' => $emptySlice,
                'outbound' => $emptySlice,
            ];
        }

        $focusId = (int) ($focusArticles[0]['article_id'] ?? 0);
        if ($focusId <= 0) {
            return [
                'available' => true,
                'inbound' => $emptySlice,
                'outbound' => $emptySlice,
            ];
        }

        $siteScoped = static function ($q) use ($siteId): void {
            $q->where('site_id', $siteId);
        };

        $inboundRows = SeoLinkMap::query()
            ->where('link_type', SeoLinkMapType::Internal->value)
            ->where('target_article_id', $focusId)
            ->whereHas('sourceArticle', $siteScoped)
            ->whereHas('targetArticle', $siteScoped)
            ->orderBy('id')
            ->get(['id', 'keyword_id', 'source_article_id', 'target_article_id', 'anchor_text', 'link_type', 'status']);

        $inbound = [];
        foreach ($inboundRows as $row) {
            $inbound[] = [
                'link_map_id' => (int) $row->id,
                'keyword_id' => (int) ($row->keyword_id ?? 0) ?: null,
                'source_article_id' => (int) ($row->source_article_id ?? 0) ?: null,
                'target_article_id' => (int) ($row->target_article_id ?? 0) ?: null,
                'anchor_text' => $row->anchor_text !== null ? (string) $row->anchor_text : null,
                'link_type' => $row->link_type instanceof \BackedEnum ? $row->link_type->value : (string) ($row->link_type ?? ''),
                'status' => $row->status instanceof \BackedEnum ? $row->status->value : (string) ($row->status ?? ''),
                'direction' => 'inbound',
            ];
        }

        $outboundRows = SeoLinkMap::query()
            ->where('link_type', SeoLinkMapType::Internal->value)
            ->where('source_article_id', $focusId)
            ->whereHas('sourceArticle', $siteScoped)
            ->whereHas('targetArticle', $siteScoped)
            ->orderBy('id')
            ->get(['id', 'keyword_id', 'source_article_id', 'target_article_id', 'anchor_text', 'link_type', 'status']);

        $outbound = [];
        foreach ($outboundRows as $row) {
            $outbound[] = [
                'link_map_id' => (int) $row->id,
                'keyword_id' => (int) ($row->keyword_id ?? 0) ?: null,
                'source_article_id' => (int) ($row->source_article_id ?? 0) ?: null,
                'target_article_id' => (int) ($row->target_article_id ?? 0) ?: null,
                'anchor_text' => $row->anchor_text !== null ? (string) $row->anchor_text : null,
                'link_type' => $row->link_type instanceof \BackedEnum ? $row->link_type->value : (string) ($row->link_type ?? ''),
                'status' => $row->status instanceof \BackedEnum ? $row->status->value : (string) ($row->status ?? ''),
                'direction' => 'outbound',
            ];
        }

        return [
            'available' => true,
            'inbound' => RelationshipListSlice::fromAll($inbound, KeywordRelationship::LINK_LIMIT)->toArray(),
            'outbound' => RelationshipListSlice::fromAll($outbound, KeywordRelationship::LINK_LIMIT)->toArray(),
        ];
    }

    /**
     * @return array{available: bool, query_mappings: array<string, mixed>}
     */
    private function loadGsc(int $siteId, int $keywordId): array
    {
        if (! $this->tableReady('seo_gsc_query_mappings')) {
            return [
                'available' => false,
                'query_mappings' => RelationshipListSlice::fromAll([], KeywordRelationship::GSC_LIMIT)->toArray(),
            ];
        }

        $rows = SeoGscQueryMapping::query()
            ->where('site_id', $siteId)
            ->where('keyword_id', $keywordId)
            ->whereIn('mapping_type', self::GSC_CANONICAL_TYPES)
            ->orderByDesc('confidence')
            ->orderBy('id')
            ->get([
                'id', 'public_ref', 'normalized_query', 'sample_query',
                'mapping_type', 'confidence', 'source', 'status',
            ]);

        $items = [];
        foreach ($rows as $row) {
            $type = $row->mapping_type instanceof GscQueryMappingType
                ? $row->mapping_type->value
                : (string) ($row->mapping_type ?? '');
            $items[] = [
                'mapping_ref' => (string) ($row->public_ref ?: ('gsc_query_mapping:'.(int) $row->id)),
                'id' => (int) $row->id,
                'normalized_query' => (string) ($row->normalized_query ?? ''),
                'sample_query' => (string) ($row->sample_query ?? ''),
                'mapping_type' => $type,
                'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                'source' => $row->source !== null ? (string) $row->source : null,
                'status' => $row->status instanceof \BackedEnum ? $row->status->value : (string) ($row->status ?? ''),
            ];
        }

        return [
            'available' => true,
            'query_mappings' => RelationshipListSlice::fromAll($items, KeywordRelationship::GSC_LIMIT)->toArray(),
        ];
    }

    /**
     * @return array{available: bool, items: array<string, mixed>}
     */
    private function loadPlanning(int $siteId, string $phrase): array
    {
        $needle = mb_strtolower(trim($phrase), 'UTF-8');
        if ($needle === '' || ! $this->tableReady('seo_project_tasks')) {
            return [
                'available' => $this->tableReady('seo_project_tasks'),
                'items' => RelationshipListSlice::fromAll([], KeywordRelationship::PLANNING_LIMIT)->toArray(),
            ];
        }

        $tasks = SeoProjectTask::query()
            ->where('site_id', $siteId)
            ->where('type', SeoProjectTask::TYPE_CREATE)
            ->whereNull('archived_at')
            ->whereRaw('LOWER(TRIM(source_content)) = ?', [$needle])
            ->orderByDesc('id')
            ->limit(KeywordRelationship::PLANNING_LIMIT * 2)
            ->get(['id', 'project_id', 'status', 'type', 'source_content', 'keyword', 'title', 'target_date', 'planning_month']);

        $items = [];
        foreach ($tasks as $task) {
            $items[] = [
                'task_ref' => 'project_task:'.(int) $task->id,
                'task_id' => (int) $task->id,
                'project_id' => (int) ($task->project_id ?? 0) ?: null,
                'status' => (string) ($task->status ?? ''),
                'type' => (string) ($task->type ?? ''),
                'title' => (string) ($task->title ?? ''),
                'keyword' => (string) ($task->keyword ?? ''),
                'target_date' => $task->target_date?->toDateString(),
                'planning_month' => $task->planning_month !== null ? (string) $task->planning_month : null,
            ];
        }

        return [
            'available' => true,
            'items' => RelationshipListSlice::fromAll($items, KeywordRelationship::PLANNING_LIMIT)->toArray(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $topics
     * @param  list<array<string, mixed>>  $focusArticles
     * @return list<string>
     */
    private function detectRelationIssues(array $topics, array $focusArticles): array
    {
        $issues = [];
        if ($focusArticles !== [] && $topics === []) {
            $issues[] = 'focus_article_without_topic';
        }
        if ($topics === []) {
            $issues[] = 'topic_missing';
        }
        if ($focusArticles === []) {
            $issues[] = 'focus_article_missing';
        }

        return $issues;
    }

    private function tableReady(string $table): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
