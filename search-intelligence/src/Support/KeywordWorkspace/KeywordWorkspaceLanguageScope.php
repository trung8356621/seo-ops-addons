<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace;

use Illuminate\Database\Eloquent\Builder;
use Omnichannel\Addons\Content\Services\ContentLanguageLegacyRepair;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;

final class KeywordWorkspaceLanguageScope
{
    /**
     * @return list<string>|null
     */
    public static function variantsForCode(?string $code): ?array
    {
        $normalized = trim((string) ($code ?? ''));
        if ($normalized === '') {
            return null;
        }

        $variants = ContentLanguageLegacyRepair::knownStoredVariants($normalized);

        return $variants === [] ? null : $variants;
    }

    /**
     * @param  Builder<Keyword>  $query
     * @param  list<string>  $languageVariants
     * @return Builder<Keyword>
     */
    public static function applyToKeywordQuery(Builder $query, array $languageVariants): Builder
    {
        if ($languageVariants === []) {
            return $query;
        }

        return $query->where(function (Builder $scopeQuery) use ($languageVariants): void {
            $scopeQuery
                ->whereHas(
                    'mainArticles',
                    static fn (Builder $articleQuery): Builder => $articleQuery->whereIn('language', $languageVariants),
                )
                ->orWhereHas(
                    'linkMaps',
                    static fn (Builder $mapQuery): Builder => $mapQuery
                        ->whereNotNull('source_article_id')
                        ->whereHas(
                            'sourceArticle',
                            static fn (Builder $articleQuery): Builder => $articleQuery->whereIn('language', $languageVariants),
                        ),
                );
        });
    }

    /**
     * @param  Builder<\Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap>  $query
     * @param  list<string>  $languageVariants
     * @return Builder<\Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap>
     */
    public static function applyToSeoLinkMapQuery(Builder $query, array $languageVariants): Builder
    {
        if ($languageVariants === []) {
            return $query;
        }

        return $query->whereHas(
            'sourceArticle',
            static fn (Builder $articleQuery): Builder => $articleQuery->whereIn('language', $languageVariants),
        );
    }
}
