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
     * @param  list<string>|null  $typeFilter  null=all, or subset of 'managed_cross_site','wiki_trust','needs_review'
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
        if ($siteId <= 0 || ! Schema::hasTable('seo_link_maps')) {
            return ['available' => false, 'items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $allowedTypes = $this->resolveAllowedTypes($typeFilter);
        if ($allowedTypes === []) {
            return ['available' => true, 'items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        // Base query — site-scoped via source article.
        $baseQuery = SeoLinkMap::query()
            ->whereIn('link_type', $allowedTypes)
            ->whereHas('sourceArticle', static fn ($q) => $q->where('site_id', $siteId))
            ->where(static function ($q): void {
                // Exclude rows explicitly flagged as not semantic eligible.
                // NULL means legacy row (treat as eligible for compat).
                $q->whereNull('is_semantic_eligible')
                    ->orWhere('is_semantic_eligible', true);
            });

        $total = (clone $baseQuery)->count();

        $maps = $baseQuery
            ->orderBy('seo_link_maps.id')
            ->skip($offset)
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
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
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

        return [
            'map_id' => (int) $map->id,
            'link_type' => $linkType->value,
            'link_type_label' => $linkType->label(),
            'is_cta' => $linkType->isCta(),
            'is_semantic_eligible' => $map->is_semantic_eligible ?? true,
            // Source
            'source_site_id' => (int) ($sourceArticle?->site_id ?? 0),
            'source_article_id' => $sourceArticle instanceof SeoArticle ? (int) $sourceArticle->id : null,
            'source_article_title' => trim((string) ($sourceArticle?->title ?? '')),
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
}
