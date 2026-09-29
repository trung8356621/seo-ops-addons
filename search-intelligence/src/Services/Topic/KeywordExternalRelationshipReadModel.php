<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;

/**
 * Keywords External — semantic cross-site relationship read model.
 *
 * Shows SEO keyword relationships that LEAVE the current site.
 * This is NOT a 404/link-health surface.
 *
 * Scope rules:
 * - managed_cross_site  → show with full resolution
 * - wiki_trust          → show as reference
 * - needs_review        → show as review signal
 * - external (compat)   → show unless is_semantic_eligible=false
 * - social / contact    → EXCLUDED (do not pollute keyword topology)
 * - internal            → EXCLUDED (not leaving the site)
 *
 * Source: seo_link_maps table (backed by ArticleLinkContextMapService ingestion).
 */
final class KeywordExternalRelationshipReadModel
{
    private const SEMANTIC_TYPES = [
        SeoLinkMapType::ManagedCrossSite->value,
        SeoLinkMapType::WikiTrust->value,
        SeoLinkMapType::NeedsReview->value,
        SeoLinkMapType::External->value,  // backward compat rows
    ];

    private const EXCLUDED_TYPES = [
        SeoLinkMapType::Internal->value,
        SeoLinkMapType::Social->value,
        SeoLinkMapType::Contact->value,
    ];

    public function __construct(
        private ?TargetKeywordResolver $targetKeywordResolver = null,
    ) {
        $this->targetKeywordResolver ??= new TargetKeywordResolver();
    }

    /**
     * UI category keys. Social and contact never map onto these.
     *
     * @return list<string>
     */
    public static function uiCategories(): array
    {
        return ['all', 'managed_cross_site', 'reference', 'needs_review'];
    }

    /**
     * UI risk filter keys.
     *
     * @return list<string>
     */
    public static function uiRiskFilters(): array
    {
        return ['all', 'safe', 'low', 'review'];
    }

    /**
     * User-facing risk level internal keys.
     *
     * @return list<string>
     */
    public static function riskLevels(): array
    {
        return ['safe', 'low', 'review'];
    }

    /**
     * Derive internal risk level from link type.
     */
    public function riskLevelForType(string|SeoLinkMapType $linkType): string
    {
        $type = $linkType instanceof SeoLinkMapType
            ? $linkType
            : SeoLinkMapType::tryFrom((string) $linkType);

        return $type?->semanticRisk() ?? 'review';
    }

    /**
     * @return list<string>|null  null = all semantic types
     */
    public function typesForRiskLevel(string $riskLevel): ?array
    {
        return match ($riskLevel) {
            'safe' => [SeoLinkMapType::ManagedCrossSite->value],
            'low' => [SeoLinkMapType::WikiTrust->value],
            'review' => [SeoLinkMapType::NeedsReview->value, SeoLinkMapType::External->value],
            default => null,
        };
    }

    /**
     * @return list<string>|null  null = all semantic types
     */
    public function typesForUiCategory(string $category): ?array
    {
        return match ($category) {
            'managed_cross_site' => [SeoLinkMapType::ManagedCrossSite->value],
            'reference' => [SeoLinkMapType::WikiTrust->value],
            'needs_review' => [SeoLinkMapType::NeedsReview->value, SeoLinkMapType::External->value],
            default => null,
        };
    }

    /**
     * @return array{all: int, safe: int, low: int, review: int, available: bool}
     */
    public function riskCounts(int $siteId): array
    {
        $categoryCounts = $this->categoryCounts($siteId);

        return [
            'all' => $categoryCounts['all'],
            'safe' => $categoryCounts['managed_cross_site'],
            'low' => $categoryCounts['reference'],
            'review' => $categoryCounts['needs_review'],
            'available' => $categoryCounts['available'],
        ];
    }

    /**
     * @return array{all: int, managed_cross_site: int, reference: int, needs_review: int, available: bool}
     */
    public function categoryCounts(int $siteId): array
    {
        $empty = [
            'all' => 0,
            'managed_cross_site' => 0,
            'reference' => 0,
            'needs_review' => 0,
            'available' => false,
        ];
        if ($siteId <= 0 || ! $this->linkMapsAvailable()) {
            return $empty;
        }

        $grouped = [];
        $rows = $this->baseQuery($siteId, self::SEMANTIC_TYPES)
            ->toBase()
            ->select('seo_link_maps.link_type')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('seo_link_maps.link_type')
            ->get();
        foreach ($rows as $row) {
            $grouped[(string) ($row->link_type ?? '')] = (int) ($row->aggregate ?? 0);
        }

        $managed = (int) ($grouped[SeoLinkMapType::ManagedCrossSite->value] ?? 0);
        $reference = (int) ($grouped[SeoLinkMapType::WikiTrust->value] ?? 0);
        $review = (int) ($grouped[SeoLinkMapType::NeedsReview->value] ?? 0)
            + (int) ($grouped[SeoLinkMapType::External->value] ?? 0);

        return [
            'all' => $managed + $reference + $review,
            'managed_cross_site' => $managed,
            'reference' => $reference,
            'needs_review' => $review,
            'available' => true,
        ];
    }

    /**
     * @param  list<string>|null  $typeFilter  null=all semantic types
     * @return array{
     *   available: bool,
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int
     * }
     */
    public function externalLinks(
        int $siteId,
        array $typeFilter = null,
        int $page = 1,
        int $perPage = 50,
    ): array {
        return $this->paginate($siteId, $typeFilter, null, $page, $perPage);
    }

    /**
     * @return array{available: bool, items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function externalLinksForRiskLevel(
        int $siteId,
        string $riskLevel,
        int $page = 1,
        int $perPage = 50,
    ): array {
        return $this->paginate($siteId, $this->typesForRiskLevel($riskLevel), null, $page, $perPage);
    }

    /**
     * @return array{available: bool, items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function externalLinksForUiCategory(
        int $siteId,
        string $category,
        int $page = 1,
        int $perPage = 50,
    ): array {
        return $this->paginate($siteId, $this->typesForUiCategory($category), null, $page, $perPage);
    }

    /**
     * Keyword-level cross-site rows for one source keyword. No fake target keyword.
     *
     * @return array{available: bool, items: list<array<string, mixed>>}
     */
    public function forKeyword(int $siteId, int $keywordId, int $limit = 40): array
    {
        if ($keywordId <= 0) {
            return ['available' => true, 'items' => []];
        }

        $page = $this->paginate($siteId, null, [$keywordId], 1, $limit);

        return [
            'available' => $page['available'],
            'items' => $page['items'],
        ];
    }

    /**
     * Source-topic keyword relationships that leave the site.
     * Target article resolution is not required.
     *
     * @return array{available: bool, items: list<array<string, mixed>>}
     */
    public function forTopic(int $siteId, int $topicId, int $limit = 40): array
    {
        if ($siteId <= 0 || $topicId <= 0 || ! $this->linkMapsAvailable()) {
            return ['available' => false, 'items' => []];
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_topic_keywords')) {
            return ['available' => true, 'items' => []];
        }

        $keywordIds = DB::connection('omi_seo_ai')
            ->table('seo_topic_keywords')
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->limit(200)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($keywordIds === []) {
            return ['available' => true, 'items' => []];
        }

        $page = $this->paginate($siteId, null, $keywordIds, 1, $limit);

        return [
            'available' => $page['available'],
            'items' => $page['items'],
        ];
    }

    /**
     * @param  list<string>|null  $typeFilter
     * @param  list<int>|null  $keywordIds
     * @return array{available: bool, items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    private function paginate(
        int $siteId,
        ?array $typeFilter,
        ?array $keywordIds,
        int $page,
        int $perPage,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        if ($siteId <= 0 || ! $this->linkMapsAvailable()) {
            return ['available' => false, 'items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        $allowedTypes = $this->resolveAllowedTypes($typeFilter);
        if ($allowedTypes === []) {
            return ['available' => true, 'items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        $baseQuery = $this->baseQuery($siteId, $allowedTypes, $keywordIds);
        $total = (clone $baseQuery)->count();

        $maps = $baseQuery
            ->orderBy('seo_link_maps.id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->with([
                'keyword:id,phrase',
                'sourceArticle:id,site_id,title,slug',
                'targetArticle:id,site_id,title,slug',
            ])
            ->get();

        $items = [];
        foreach ($maps as $map) {
            if (! $map instanceof SeoLinkMap) {
                continue;
            }

            $items[] = $this->presentMap($map);
        }

        return [
            'available' => true,
            'items' => $this->attachSourceTopics($items, $siteId),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function linkMapsAvailable(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_link_maps');
    }

    /**
     * @param  list<string>  $allowedTypes
     * @param  list<int>|null  $keywordIds
     */
    private function baseQuery(int $siteId, array $allowedTypes, ?array $keywordIds = null)
    {
        $query = SeoLinkMap::query()
            ->whereIn('link_type', $allowedTypes)
            ->whereHas('sourceArticle', static fn ($q) => $q->where('site_id', $siteId))
            ->where(static function ($q): void {
                // Exclude rows explicitly flagged as not semantic eligible.
                // NULL means legacy row (treat as eligible for compat).
                $q->whereNull('is_semantic_eligible')
                    ->orWhere('is_semantic_eligible', true);
            });

        if ($keywordIds !== null) {
            $query->whereIn('keyword_id', $keywordIds);
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function resolveAllowedTypes(?array $typeFilter): array
    {
        if ($typeFilter === null) {
            return self::SEMANTIC_TYPES;
        }

        return array_values(array_intersect($typeFilter, self::SEMANTIC_TYPES));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMap(SeoLinkMap $map): array
    {
        $keyword = $map->keyword;
        $sourceArticle = $map->sourceArticle;
        $targetArticle = $map->targetArticle;
        $linkType = $map->link_type instanceof SeoLinkMapType
            ? $map->link_type
            : SeoLinkMapType::External;

        $targetSiteId = null;
        $targetArticleId = null;

        if ($targetArticle instanceof SeoArticle) {
            $targetSiteId = (int) ($targetArticle->site_id ?? 0);
            $targetArticleId = (int) $targetArticle->id;
        } elseif ((int) ($map->target_site_id ?? 0) > 0) {
            $targetSiteId = (int) $map->target_site_id;
        }

        $targetKeyword = null;
        if ($targetArticle instanceof SeoArticle) {
            $targetKeyword = $this->targetKeywordResolver->resolveForArticle((int) $targetArticle->id, $targetSiteId);
        }

        $riskLevel = $this->riskLevelForType($linkType);

        return [
            'map_id' => (int) $map->id,
            'link_type' => $linkType->value,
            'link_type_label' => $linkType->label(),
            'ui_category' => $this->uiCategoryForType($linkType),
            'risk_level' => $riskLevel,
            'risk_level_label' => match ($riskLevel) {
                'safe' => __('seo-content-ai::filament.keyword.risk_level_safe'),
                'low' => __('seo-content-ai::filament.keyword.risk_level_low'),
                default => __('seo-content-ai::filament.keyword.risk_level_review'),
            },
            'risk_reason' => match ($riskLevel) {
                'safe' => __('seo-content-ai::filament.keyword.risk_reason_safe'),
                'low' => __('seo-content-ai::filament.keyword.risk_reason_low'),
                default => __('seo-content-ai::filament.keyword.risk_reason_review'),
            },
            'is_cta' => $linkType->isCta(),
            'is_semantic_eligible' => $map->is_semantic_eligible ?? true,
            // Source
            'source_site_id' => (int) ($sourceArticle?->site_id ?? 0),
            'source_article_id' => $sourceArticle instanceof SeoArticle ? (int) $sourceArticle->id : null,
            'source_article_title' => trim((string) ($sourceArticle?->title ?? '')),
            'source_topic_id' => null,
            'source_topic_name' => null,
            // Keyword (source)
            'source_keyword_id' => $keyword instanceof Keyword ? (int) $keyword->id : null,
            'source_keyword_ref' => $keyword instanceof Keyword ? 'keyword:'.(int) $keyword->id : null,
            'source_keyword_phrase' => $keyword instanceof Keyword ? (string) $keyword->phrase : (string) $map->anchor_text,
            'keyword_id' => $keyword instanceof Keyword ? (int) $keyword->id : null,
            'keyword_ref' => $keyword instanceof Keyword ? 'keyword:'.(int) $keyword->id : null,
            'keyword_phrase' => $keyword instanceof Keyword ? (string) $keyword->phrase : (string) $map->anchor_text,
            'anchor_text' => (string) $map->anchor_text,
            // Target
            'target_site_id' => $targetSiteId,
            'target_site_ref' => $targetSiteId !== null && $targetSiteId > 0 ? 'site:'.$targetSiteId : null,
            'target_article_id' => $targetArticleId,
            'target_article_ref' => $targetArticleId !== null ? 'article:'.$targetArticleId : null,
            'target_article_title' => $targetArticle instanceof SeoArticle
                ? trim((string) ($targetArticle->title ?? ''))
                : null,
            'target_external_url' => $targetArticle === null
                ? trim((string) ($map->target_external_url ?? ''))
                : null,
            // Target Keyword
            'target_keyword_id' => $targetKeyword instanceof Keyword ? (int) $targetKeyword->id : null,
            'target_keyword_ref' => $targetKeyword instanceof Keyword ? 'keyword:'.(int) $targetKeyword->id : null,
            'target_keyword_phrase' => $targetKeyword instanceof Keyword ? (string) $targetKeyword->phrase : null,
            // Resolution state
            'target_resolved' => $targetArticle instanceof SeoArticle,
            'target_keyword_resolved' => $targetKeyword instanceof Keyword,
            'destination_kind' => $map->destination_kind?->value,
        ];
    }

    private function uiCategoryForType(SeoLinkMapType $linkType): string
    {
        return match ($linkType) {
            SeoLinkMapType::ManagedCrossSite => 'managed_cross_site',
            SeoLinkMapType::WikiTrust => 'reference',
            default => 'needs_review',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function attachSourceTopics(array $items, int $siteId): array
    {
        if ($items === [] || $siteId <= 0) {
            return $items;
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_topic_keywords')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_topics')) {
            return $items;
        }

        $keywordIds = [];
        foreach ($items as $item) {
            $keywordId = (int) ($item['source_keyword_id'] ?? 0);
            if ($keywordId > 0) {
                $keywordIds[$keywordId] = true;
            }
        }
        if ($keywordIds === []) {
            return $items;
        }

        $rows = DB::connection('omi_seo_ai')
            ->table('seo_topic_keywords as stk')
            ->join('seo_topics as st', 'st.id', '=', 'stk.topic_id')
            ->where('stk.site_id', $siteId)
            ->where('st.site_id', $siteId)
            ->whereIn('stk.keyword_id', array_keys($keywordIds))
            ->get(['stk.keyword_id', 'stk.topic_id', 'st.name']);

        $byKeyword = [];
        foreach ($rows as $row) {
            $keywordId = (int) ($row->keyword_id ?? 0);
            if ($keywordId <= 0 || isset($byKeyword[$keywordId])) {
                continue;
            }
            $byKeyword[$keywordId] = $row;
        }

        foreach ($items as $index => $item) {
            $keywordId = (int) ($item['source_keyword_id'] ?? 0);
            $topic = $byKeyword[$keywordId] ?? null;
            if ($topic === null) {
                continue;
            }
            $name = trim((string) ($topic->name ?? ''));
            $items[$index]['source_topic_id'] = (int) ($topic->topic_id ?? 0) ?: null;
            $items[$index]['source_topic_name'] = $name !== '' ? $name : null;
        }

        return $items;
    }
}
