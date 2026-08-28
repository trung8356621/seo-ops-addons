<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Support\InternalAnchorKeywordFilter;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;

/**
 * Single source of truth for user-facing Keywords UI inventory.
 *
 * Matches Dictionary card "Tất cả từ khóa" base:
 * site + selected language + linked source + exclude TYPE_SUGGEST
 * + minimum word count + exclude link-like phrases.
 *
 * Cluster / classification UI counters must consume this — never a broader forSite scope.
 */
final class KeywordUiInventoryQuery
{
    /**
     * @param  Builder<Keyword>  $query
     * @param  list<string>|null  $languageVariants
     * @return Builder<Keyword>
     */
    public function apply(Builder $query, ?int $siteId, ?array $languageVariants = null): Builder
    {
        $query = KeywordResource::excludeStagingSuggestTypes($query);

        if ($siteId !== null && $siteId > 0) {
            $query->forSite($siteId);
        }

        $query->whereHas(
            'linkMaps',
            static fn (Builder $mapQuery): Builder => $mapQuery->whereNotNull('source_article_id'),
        );

        if ($languageVariants !== null && $languageVariants !== []) {
            $query = KeywordWorkspaceLanguageScope::applyToKeywordQuery($query, $languageVariants);
        }

        $query = KeywordResource::applyMinimumKeywordWordCount($query);

        return InternalAnchorKeywordFilter::applyExcludeLinkLikePhrases($query);
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @return Builder<Keyword>
     */
    public function baseQuery(?int $siteId, ?array $languageVariants = null): Builder
    {
        return $this->apply(Keyword::query(), $siteId, $languageVariants);
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @return list<int>
     */
    public function keywordIds(?int $siteId, ?array $languageVariants = null): array
    {
        if ($siteId === null || $siteId <= 0) {
            return [];
        }

        return $this->baseQuery($siteId, $languageVariants)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<string>|null  $languageVariants
     */
    public function keywordIdSubquery(?int $siteId, ?array $languageVariants = null): QueryBuilder
    {
        $scoped = $this->baseQuery($siteId, $languageVariants)->select('keywords.id');

        return DB::connection('omi_seo_ai')
            ->query()
            ->fromSub($scoped->getQuery(), 'keyword_ui_inventory')
            ->select('id');
    }

    /**
     * @param  list<string>|null  $languageVariants
     */
    public function count(?int $siteId, ?array $languageVariants = null): int
    {
        if ($siteId === null || $siteId <= 0) {
            return 0;
        }

        return (int) $this->baseQuery($siteId, $languageVariants)->count();
    }

    /**
     * Diagnostic: IDs present in linked+language+no-Suggest inventory but excluded
     * by Dictionary word-count / link-like filters (the historical 3036 vs 3059 gap).
     *
     * @param  list<string>|null  $languageVariants
     * @return array{
     *     dictionary_ids: list<int>,
     *     linked_language_ids: list<int>,
     *     excluded_ids: list<int>,
     *     suggest_count: int,
     *     unlinked_count: int,
     *     other_excluded_count: int
     * }
     */
    public function debugInventoryDiff(?int $siteId, ?array $languageVariants = null): array
    {
        if ($siteId === null || $siteId <= 0) {
            return [
                'dictionary_ids' => [],
                'linked_language_ids' => [],
                'excluded_ids' => [],
                'suggest_count' => 0,
                'unlinked_count' => 0,
                'other_excluded_count' => 0,
            ];
        }

        $dictionaryIds = $this->keywordIds($siteId, $languageVariants);

        $linkedLanguage = KeywordResource::excludeStagingSuggestTypes(Keyword::query()->forSite($siteId));
        $linkedLanguage->whereHas(
            'linkMaps',
            static fn (Builder $mapQuery): Builder => $mapQuery->whereNotNull('source_article_id'),
        );
        if ($languageVariants !== null && $languageVariants !== []) {
            $linkedLanguage = KeywordWorkspaceLanguageScope::applyToKeywordQuery($linkedLanguage, $languageVariants);
        }
        $linkedLanguageIds = $linkedLanguage
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $dictionarySet = array_fill_keys($dictionaryIds, true);
        $excludedIds = array_values(array_filter(
            $linkedLanguageIds,
            static fn (int $id): bool => ! isset($dictionarySet[$id]),
        ));

        $siteWide = Keyword::query()->forSite($siteId);
        if ($languageVariants !== null && $languageVariants !== []) {
            $siteWide = KeywordWorkspaceLanguageScope::applyToKeywordQuery($siteWide, $languageVariants);
        }
        $siteWideIds = $siteWide->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $linkedSet = array_fill_keys($linkedLanguageIds, true);

        $suggestIds = Keyword::query()
            ->forSite($siteId)
            ->where('type', Keyword::TYPE_SUGGEST)
            ->when(
                $languageVariants !== null && $languageVariants !== [],
                static function (Builder $q) use ($languageVariants): Builder {
                    return KeywordWorkspaceLanguageScope::applyToKeywordQuery($q, $languageVariants);
                },
            )
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $suggestSet = array_fill_keys($suggestIds, true);

        $unlinkedNonSuggest = count(array_filter(
            $siteWideIds,
            static function (int $id) use ($linkedSet, $suggestSet): bool {
                return ! isset($linkedSet[$id]) && ! isset($suggestSet[$id]);
            },
        ));

        return [
            'dictionary_ids' => $dictionaryIds,
            'linked_language_ids' => $linkedLanguageIds,
            'excluded_ids' => $excludedIds,
            'suggest_count' => count($suggestIds),
            'unlinked_count' => $unlinkedNonSuggest,
            'other_excluded_count' => count($excludedIds),
        ];
    }
}
