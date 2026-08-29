<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace;

use Illuminate\Database\Eloquent\Builder;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterEligibility;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordRuleClassifier;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagQuery;

/**
 * Single filtered base query for Keyword Dictionary UI.
 *
 * Listing / pagination / summary cards / classification counters must all
 * start from {@see filtered()} with the same site + language + filter bag.
 */
final class KeywordDictionaryQuery
{
    public function __construct(
        private readonly KeywordUiInventoryQuery $inventory,
    ) {}

    /**
     * @param  list<string>|null  $languageVariants
     * @param  array{
     *     cluster_key?: string|null,
     *     search?: string|null,
     *     focus?: bool,
     *     seo_hidden?: bool|null,
     *     tags?: list<mixed>,
     *     kinds?: list<mixed>,
     *     intents?: list<mixed>,
     *     sources?: list<mixed>,
     *     types?: list<mixed>,
     * }  $filters
     * @return Builder<Keyword>
     */
    public function filtered(?int $siteId, ?array $languageVariants = null, array $filters = []): Builder
    {
        return $this->applyTo(Keyword::query(), $siteId, $languageVariants, $filters);
    }

    /**
     * Apply Dictionary UI filters onto an existing base query (e.g. Filament getEloquentQuery).
     *
     * @param  Builder<Keyword>  $query
     * @param  list<string>|null  $languageVariants
     * @param  array<string, mixed>  $filters
     * @return Builder<Keyword>
     */
    public function applyTo(Builder $query, ?int $siteId, ?array $languageVariants = null, array $filters = []): Builder
    {
        $query = $this->inventory->apply($query, $siteId, $languageVariants);

        if (($filters['focus'] ?? false) === true) {
            $query->whereHas('mainArticles');
        }

        $query = $this->applyClusterKey($query, isset($filters['cluster_key']) ? (string) $filters['cluster_key'] : null);
        $query = $this->applySearch($query, isset($filters['search']) ? (string) $filters['search'] : null);
        $query = $this->applySeoHidden($query, array_key_exists('seo_hidden', $filters) ? $filters['seo_hidden'] : null);
        $query = app(KeywordTagQuery::class)->apply($query, is_array($filters['tags'] ?? null) ? $filters['tags'] : []);
        $query = KeywordClassificationVisibility::applyKindFilter(
            $query,
            is_array($filters['kinds'] ?? null) ? $filters['kinds'] : [],
        );
        $query = $this->applyIntents($query, is_array($filters['intents'] ?? null) ? $filters['intents'] : []);
        $query = $this->applySources($query, is_array($filters['sources'] ?? null) ? $filters['sources'] : []);
        $query = $this->applyTypes($query, is_array($filters['types'] ?? null) ? $filters['types'] : []);

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
    public function applyClusterKey(Builder $query, ?string $clusterKey): Builder
    {
        $key = trim((string) ($clusterKey ?? ''));
        if ($key === '') {
            return $query;
        }

        if ($key === '_none') {
            return app(KeywordClusterEligibility::class)->applyUnclusteredSeoKeywordScope($query);
        }

        return $query->whereHas(
            'seoClassification',
            static fn (Builder $classification): Builder => $classification->where('cluster_key', $key),
        );
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
     * @param  list<mixed>  $intents
     * @return Builder<Keyword>
     */
    public function applyIntents(Builder $query, array $intents): Builder
    {
        $allowed = KeywordRuleClassifier::intents();
        $selected = collect($intents)
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->filter(static fn (string $value): bool => in_array($value, $allowed, true))
            ->values()
            ->all();
        if ($selected === []) {
            return $query;
        }

        return $query->whereHas(
            'seoClassification',
            static fn (Builder $classification): Builder => $classification->whereIn('seo_intent', $selected),
        );
    }

    /**
     * @param  Builder<Keyword>  $query
     * @param  list<mixed>  $sources
     * @return Builder<Keyword>
     */
    public function applySources(Builder $query, array $sources): Builder
    {
        $allowed = KeywordSourceNormalizer::all();
        $selected = collect($sources)
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->filter(static fn (string $value): bool => in_array($value, $allowed, true))
            ->values()
            ->all();
        if ($selected === []) {
            return $query;
        }

        return $query->whereHas(
            'seoClassification',
            static fn (Builder $classification): Builder => $classification->whereIn('source_kind', $selected),
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
