<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagQuery;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;

/**
 * Single filtered base query for Keyword Dictionary UI.
 *
 * Listing / pagination / summary cards must all start from {@see filtered()}
 * with the same site + language + filter bag.
 */
final class KeywordDictionaryQuery
{
    public function __construct(
        private readonly KeywordUiInventoryQuery $inventory,
    ) {}

    /**
     * @param  list<string>|null  $languageVariants
     * @param  array{
     *     search?: string|null,
     *     focus?: bool,
     *     seo_hidden?: bool|null,
     *     tags?: list<mixed>,
     *     types?: list<mixed>,
     *     topic_assignment?: string|null,
     * }  $filters
     * @return Builder<Keyword>
     */
    public function filtered(?int $siteId, ?array $languageVariants = null, array $filters = []): Builder
    {
        return $this->applyTo(Keyword::query(), $siteId, $languageVariants, $filters, true);
    }

    /**
     * Filtered Dictionary population without row-only projections.
     *
     * @param  list<string>|null  $languageVariants
     * @param  array<string, mixed>  $filters
     * @return Builder<Keyword>
     */
    public function filteredForSummary(?int $siteId, ?array $languageVariants = null, array $filters = []): Builder
    {
        return $this->applyTo(Keyword::query(), $siteId, $languageVariants, $filters, false);
    }

    /**
     * Apply Dictionary UI filters onto an existing base query (e.g. Filament getEloquentQuery).
     *
     * @param  Builder<Keyword>  $query
     * @param  list<string>|null  $languageVariants
     * @param  array<string, mixed>  $filters
     * @return Builder<Keyword>
     */
    public function applyTo(
        Builder $query,
        ?int $siteId,
        ?array $languageVariants = null,
        array $filters = [],
        bool $withListState = true,
    ): Builder {
        $query = $this->inventory->apply($query, $siteId, $languageVariants);
        if ($withListState) {
            $query = $this->withListState($query, $siteId);
        }

        if (($filters['focus'] ?? false) === true) {
            $query->whereHas('mainArticles');
        }

        $query = $this->applySearch($query, isset($filters['search']) ? (string) $filters['search'] : null);
        $query = $this->applySeoHidden($query, array_key_exists('seo_hidden', $filters) ? $filters['seo_hidden'] : null);
        $query = app(KeywordTagQuery::class)->apply($query, is_array($filters['tags'] ?? null) ? $filters['tags'] : []);
        $query = $this->applyTypes($query, is_array($filters['types'] ?? null) ? $filters['types'] : []);
        $query = $this->applyTopicAssignment(
            $query,
            $siteId,
            isset($filters['topic_assignment']) ? (string) $filters['topic_assignment'] : null,
        );

        return $query;
    }

    /**
     * Project the complete Dictionary-row contract with bounded SQL work.
     * No relationship graph is hydrated and row presentation needs no fallback queries.
     *
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function withListState(Builder $query, ?int $siteId): Builder
    {
        $siteId = (int) ($siteId ?? 0);
        $focusMetaKey = $siteId > 0 ? KeywordMetaKey::siteMainArticleId($siteId) : '';

        $query->select([
            'keywords.id',
            'keywords.phrase',
            'keywords.type',
            'keywords.review_status',
        ])->withExists([
            'linkMaps as has_site_links' => static fn (Builder $maps): Builder => $maps
                ->where('status', '!=', SeoLinkMapStatus::Ignored->value),
        ]);

        $query->withExists([
            'metas as seo_hidden' => static fn (Builder $meta): Builder => $meta
                ->where('meta_key', KeywordMetaKey::SeoHidden->value)
                ->where('meta_value', '1'),
            'metas as mcp_excluded' => static fn (Builder $meta): Builder => $meta
                ->where('meta_key', KeywordMetaKey::McpExcluded->value)
                ->where('meta_value', '1'),
        ]);

        if ($siteId <= 0) {
            return $query->selectRaw('0 as focus_article_count, 0 as linked_article_count');
        }

        // keyword_meta guarantees one row per (keyword_id, meta_key), so these
        // joins cannot multiply Dictionary rows or distort paginator counts.
        $query
            ->leftJoin('keyword_meta as list_site_focus_meta', function ($join) use ($focusMetaKey): void {
                $join->on('list_site_focus_meta.keyword_id', '=', 'keywords.id')
                    ->where('list_site_focus_meta.meta_key', '=', $focusMetaKey);
            })
            ->leftJoin('keyword_meta as list_legacy_focus_meta', function ($join): void {
                $join->on('list_legacy_focus_meta.keyword_id', '=', 'keywords.id')
                    ->where('list_legacy_focus_meta.meta_key', '=', KeywordMetaKey::MainArticleId->value);
            })
            ->leftJoin('articles as list_focus_article', function ($join) use ($siteId): void {
                $join->on('list_focus_article.id', '=', DB::raw(
                    'COALESCE(list_site_focus_meta.meta_value, list_legacy_focus_meta.meta_value)',
                ))
                    ->where('list_focus_article.site_id', '=', $siteId)
                    ->whereNull('list_focus_article.deleted_at');
            })
            ->selectRaw('CASE WHEN list_focus_article.id IS NULL THEN 0 ELSE 1 END as focus_article_count');

        $query->selectRaw(
            '(SELECT COUNT(DISTINCT list_maps.source_article_id) '
            .'FROM seo_link_maps list_maps '
            .'INNER JOIN articles list_sources ON list_sources.id = list_maps.source_article_id '
            .'WHERE list_maps.keyword_id = keywords.id '
            .'AND list_maps.source_article_id IS NOT NULL '
            .'AND list_maps.status != ? '
            .'AND list_sources.deleted_at IS NULL '
            .'AND list_sources.site_id = ? '
            .'AND (list_focus_article.id IS NULL '
            .'OR list_maps.source_article_id != list_focus_article.id)) as linked_article_count',
            [
                SeoLinkMapStatus::Ignored->value,
                $siteId,
            ],
        );

        return $query;
    }

    /**
     * Filter by current-site Topic membership ({@see seo_topic_keywords}).
     *
     * @param  Builder<Keyword>  $query
     * @param  'assigned'|'unassigned'|string|null  $assignment
     * @return Builder<Keyword>
     */
    public function applyTopicAssignment(Builder $query, ?int $siteId, ?string $assignment): Builder
    {
        $assignment = is_string($assignment) ? trim($assignment) : '';
        if ($assignment === '' || $siteId === null || $siteId <= 0) {
            return $query;
        }

        $membership = static function ($sub) use ($siteId): void {
            $sub->selectRaw('1')
                ->from('seo_topic_keywords')
                ->whereColumn('seo_topic_keywords.keyword_id', 'keywords.id')
                ->where('seo_topic_keywords.site_id', $siteId);
        };

        if ($assignment === 'unassigned' || $assignment === 'none' || $assignment === '0') {
            return $query->whereNotExists($membership);
        }

        if ($assignment === 'assigned' || $assignment === 'has' || $assignment === '1') {
            return $query->whereExists($membership);
        }

        return $query;
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    public function keywordIds(?int $siteId, ?array $languageVariants = null, array $filters = []): array
    {
        return $this->filtered($siteId, $languageVariants, $filters)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function applySearch(Builder $query, ?string $search): Builder
    {
        $needle = trim((string) ($search ?? ''));
        if ($needle === '') {
            return $query;
        }

        return KeywordResource::applyInsensitivePhraseSearch($query, $needle);
    }

    /**
     * Matches KeywordResource TernaryFilter seo_hidden:
     * null/blank → no visibility filter (full Dictionary, includes Exclude from SEO);
     * true → only excluded; false → only non-excluded.
     *
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function applySeoHidden(Builder $query, ?bool $seoHidden): Builder
    {
        if ($seoHidden === null) {
            return $query;
        }

        $hiddenMeta = static function (Builder $meta): Builder {
            return $meta
                ->where('meta_key', KeywordMetaKey::SeoHidden->value)
                ->where('meta_value', '1');
        };

        if ($seoHidden === true) {
            return $query->whereHas('metas', $hiddenMeta);
        }

        return $query->whereDoesntHave('metas', $hiddenMeta);
    }

    /**
     * Keywords marked Exclude from SEO (keyword_meta seo_hidden=1).
     *
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function applyExcludedFromSeo(Builder $query): Builder
    {
        return $this->applySeoHidden($query, true);
    }

    /**
     * Review bucket: underperforming review_status OR Exclude from SEO (deduped by query).
     *
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function applyUnderperformingReview(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder
                ->whereIn('review_status', ['danger', 'warning'])
                ->orWhereHas(
                    'metas',
                    static fn (Builder $meta): Builder => $meta
                        ->where('meta_key', KeywordMetaKey::SeoHidden->value)
                        ->where('meta_value', '1'),
                );
        });
    }

    /**
     * Active Dictionary card: linked + review active, not Exclude from SEO.
     *
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function applyActiveSeoKeywords(Builder $query): Builder
    {
        return $this->applySeoHidden(
            $query->where(function (Builder $builder): void {
                $builder
                    ->whereHas('mainArticles')
                    ->orWhereHas(
                        'linkMaps',
                        static fn (Builder $mapQuery): Builder => $mapQuery->whereNotNull('source_article_id'),
                    );
            })->where('review_status', 'active'),
            false,
        );
    }

    /**
     * @param  Builder<Keyword>  $query
     * @param  list<mixed>  $types
     * @return Builder<Keyword>
     */
    public function applyTypes(Builder $query, array $types): Builder
    {
        $allowed = array_keys(KeywordResource::keywordTypeFilterOptions());
        $selected = collect($types)
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->filter(static fn (string $value): bool => in_array($value, $allowed, true))
            ->values()
            ->all();
        if ($selected === []) {
            return $query;
        }

        return $query->whereIn('type', $selected);
    }
}
