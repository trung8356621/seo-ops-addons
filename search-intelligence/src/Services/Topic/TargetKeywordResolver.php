<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\KeywordMeta;

/**
 * Resolves the canonical target Keyword for a managed target Article.
 *
 * Used for cross-site link intelligence:
 *   Site A -> Site B
 *   Article A -> Article B
 *   Keyword A -> Keyword B
 *
 * Resolution strategy:
 *   1. Check site-scoped focus keyword in keyword_meta: site.{targetSiteId}.main_article_id = targetArticleId
 *   2. Check legacy global focus keyword in keyword_meta: main_article_id = targetArticleId
 *   3. Check article_meta 'seo_focus_keyword' on target Article -> resolve Keyword by phrase
 *   4. If unresolvable, returns null (never fabricates fake keywords or infers from title/slug).
 */
final class TargetKeywordResolver
{
    /** @var array<string, Keyword|null> */
    private array $cache = [];

    public function resolveForArticle(int $targetArticleId, ?int $targetSiteId = null): ?Keyword
    {
        if ($targetArticleId <= 0) {
            return null;
        }

        $cacheKey = "{$targetArticleId}:{$targetSiteId}";
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        if ($targetSiteId === null || $targetSiteId <= 0) {
            $targetSiteId = (int) SeoArticle::query()->whereKey($targetArticleId)->value('site_id');
        }

        // 1. Check site-scoped focus keyword
        if ($targetSiteId > 0) {
            $keywordId = KeywordMeta::query()
                ->where('meta_key', KeywordMetaKey::siteMainArticleId($targetSiteId))
                ->where('meta_value', (string) $targetArticleId)
                ->value('keyword_id');

            if ($keywordId !== null && (int) $keywordId > 0) {
                $keyword = Keyword::query()->find($keywordId);
                if ($keyword instanceof Keyword) {
                    return $this->cache[$cacheKey] = $keyword;
                }
            }
        }

        // 2. Check legacy global focus keyword
        $legacyKeywordId = KeywordMeta::query()
            ->where('meta_key', KeywordMetaKey::MainArticleId->value)
            ->where('meta_value', (string) $targetArticleId)
            ->value('keyword_id');

        if ($legacyKeywordId !== null && (int) $legacyKeywordId > 0) {
            $keyword = Keyword::query()->find($legacyKeywordId);
            if ($keyword instanceof Keyword) {
                return $this->cache[$cacheKey] = $keyword;
            }
        }

        // 3. Check article_meta 'seo_focus_keyword'
        try {
            $phrase = DB::connection('omi_seo_ai')
                ->table('article_meta')
                ->where('article_id', $targetArticleId)
                ->where('meta_key', 'seo_focus_keyword')
                ->value('meta_value');

            if (is_string($phrase) && trim($phrase) !== '') {
                $cleanPhrase = Keyword::preparePhraseForStorage(trim($phrase));
                if ($cleanPhrase !== '') {
                    $keyword = Keyword::query()
                        ->whereRaw('LOWER(phrase) = ?', [mb_strtolower($cleanPhrase)])
                        ->first();

                    if ($keyword instanceof Keyword) {
                        return $this->cache[$cacheKey] = $keyword;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $this->cache[$cacheKey] = null;
    }
}
