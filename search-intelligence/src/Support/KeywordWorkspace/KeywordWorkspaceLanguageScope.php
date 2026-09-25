<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace;

use Illuminate\Database\Eloquent\Builder;
use Omnichannel\Addons\Content\Services\ContentLanguageLegacyRepair;
use Omnichannel\Addons\Content\Support\ContentLanguageCodeNormalizer;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;

final class KeywordWorkspaceLanguageScope
{
    /**
     * Resolve the backend language code from the same options rendered by the selectbox.
     *
     * A stale/legacy browser value must never turn a visible concrete selection into an
     * unscoped (all-languages) query. When options exist, this always returns one of them.
     *
     * @param  array<string, string>  $options
     */
    public static function resolveSelectedCode(
        ?string $selected,
        array $options,
        ?string $primary = null,
    ): ?string {
        if ($options === []) {
            return null;
        }

        foreach ([$selected, $primary] as $candidate) {
            $normalized = ContentLanguageCodeNormalizer::normalize($candidate);
            if ($normalized !== null && isset($options[$normalized])) {
                return $normalized;
            }
        }

        $first = array_key_first($options);

        return is_string($first) && $first !== '' ? $first : null;
    }

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
    public static function applyToKeywordQuery(
        Builder $query,
        array $languageVariants,
        ?int $siteId = null,
    ): Builder {
        if ($languageVariants === []) {
            return $query;
        }

        return $query->where(function (Builder $scopeQuery) use ($languageVariants, $siteId): void {
            $scopeQuery
                ->whereHas(
                    'mainArticles',
                    static fn (Builder $articleQuery): Builder => $articleQuery
                        ->when(
                            $siteId !== null && $siteId > 0,
                            static fn (Builder $siteQuery): Builder => $siteQuery->where('site_id', $siteId),
                        )
                        ->whereIn('language', $languageVariants),
                )
                ->orWhereHas(
                    'linkMaps',
                    static fn (Builder $mapQuery): Builder => $mapQuery
                        ->whereNotNull('source_article_id')
                        ->whereHas(
                            'sourceArticle',
                            static fn (Builder $articleQuery): Builder => $articleQuery
                                ->when(
                                    $siteId !== null && $siteId > 0,
                                    static fn (Builder $siteQuery): Builder => $siteQuery->where('site_id', $siteId),
                                )
                                ->whereIn('language', $languageVariants),
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
