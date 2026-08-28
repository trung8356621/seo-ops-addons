<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordWorkspaceLanguageScope;

final class KeywordClusterSiteScope
{
    /**
     * @param  Builder<Keyword>  $query
     * @param  list<string>|null  $languageVariants
     * @return Builder<Keyword>
     */
    public static function apply(
        Builder $query,
        ?int $siteId,
        ?array $languageVariants = null,
        bool $excludeSuggest = true,
        bool $requireLinkedSource = false,
    ): Builder {
        if ($siteId !== null && $siteId > 0) {
            $query = $query->forSite($siteId);
        }

        if ($excludeSuggest) {
            $query->where(static function (Builder $typeQuery): void {
                $typeQuery
                    ->whereNull('type')
                    ->orWhere('type', '!=', Keyword::TYPE_SUGGEST);
            });
        }

        if ($requireLinkedSource) {
            $query->whereHas(
                'linkMaps',
                static fn (Builder $mapQuery): Builder => $mapQuery->whereNotNull('source_article_id'),
            );
        }

        if ($languageVariants !== null && $languageVariants !== []) {
            $query = KeywordWorkspaceLanguageScope::applyToKeywordQuery($query, $languageVariants);
        }

        return $query;
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @return list<int>
     */
    public static function keywordIds(
        ?int $siteId,
        ?array $languageVariants = null,
        bool $excludeSuggest = true,
        bool $requireLinkedSource = false,
    ): array {
        if ($siteId === null || $siteId <= 0) {
            return [];
        }

        return self::apply(Keyword::query(), $siteId, $languageVariants, $excludeSuggest, $requireLinkedSource)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<string>|null  $languageVariants
     */
    public static function keywordIdSubquery(
        ?int $siteId,
        ?array $languageVariants = null,
        bool $excludeSuggest = true,
        bool $requireLinkedSource = false,
    ): QueryBuilder {
        $scoped = self::apply(
            Keyword::query(),
            $siteId,
            $languageVariants,
            $excludeSuggest,
            $requireLinkedSource,
        )->select('keywords.id');

        return DB::connection('omi_seo_ai')
            ->query()
            ->fromSub($scoped->getQuery(), 'scoped_site_keywords')
            ->select('id');
    }
}
