<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;

/**
 * Languages evidenced by this site's articles for each keyword.
 * Codes outside the site's configured set are ignored.
 */
final class KeywordInventoryLanguageReader
{
    /**
     * @param  list<int>  $keywordIds
     * @param  list<string>  $configured
     * @return array<int, list<string>>
     */
    public function languagesFor(int $siteId, array $keywordIds, array $configured): array
    {
        if ($siteId <= 0 || $keywordIds === [] || $configured === []) {
            return [];
        }

        $allowed = [];
        foreach ($configured as $code) {
            $normalized = ArticleLanguageCode::normalize($code);
            if ($normalized !== '') {
                $allowed[$normalized] = true;
            }
        }
        if ($allowed === []) {
            return [];
        }

        $found = [];
        foreach (array_chunk($keywordIds, 500) as $chunk) {
            $this->collectLinkMapLanguages($siteId, $chunk, $allowed, $found);
            $this->collectMainArticleLanguages($siteId, $chunk, $allowed, $found);
        }

        $out = [];
        foreach ($found as $keywordId => $codes) {
            $list = array_keys($codes);
            sort($list);
            $out[$keywordId] = $list;
        }

        return $out;
    }

    /**
     * @param  list<int>  $keywordIds
     * @param  array<string, true>  $allowed
     * @param  array<int, array<string, true>>  $found
     */
    private function collectLinkMapLanguages(int $siteId, array $keywordIds, array $allowed, array &$found): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('seo_link_maps') || ! $schema->hasTable('articles') || ! $schema->hasColumn('articles', 'language')) {
            return;
        }

        $query = DB::connection('omi_seo_ai')->table('seo_link_maps as m')
            ->join('articles as a', 'a.id', '=', 'm.source_article_id')
            ->where('a.site_id', $siteId)
            ->whereIn('m.keyword_id', $keywordIds)
            ->whereNotNull('m.keyword_id')
            ->whereNotNull('a.language');
        if ($schema->hasColumn('articles', 'deleted_at')) {
            $query->whereNull('a.deleted_at');
        }

        foreach ($query->get(['m.keyword_id', 'a.language']) as $row) {
            $this->remember($found, (int) $row->keyword_id, (string) $row->language, $allowed);
        }
    }

    /**
     * @param  list<int>  $keywordIds
     * @param  array<string, true>  $allowed
     * @param  array<int, array<string, true>>  $found
     */
    private function collectMainArticleLanguages(int $siteId, array $keywordIds, array $allowed, array &$found): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('keyword_meta') || ! $schema->hasTable('articles') || ! $schema->hasColumn('articles', 'language')) {
            return;
        }

        $query = DB::connection('omi_seo_ai')->table('keyword_meta as km')
            ->join('articles as a', function ($join): void {
                $join->whereRaw('a.id = CAST(km.meta_value AS UNSIGNED)');
            })
            ->where('km.meta_key', KeywordMetaKey::siteMainArticleId($siteId))
            ->where('a.site_id', $siteId)
            ->whereIn('km.keyword_id', $keywordIds)
            ->whereNotNull('a.language');
        if ($schema->hasColumn('articles', 'deleted_at')) {
            $query->whereNull('a.deleted_at');
        }

        foreach ($query->get(['km.keyword_id', 'a.language']) as $row) {
            $this->remember($found, (int) $row->keyword_id, (string) $row->language, $allowed);
        }
    }

    /**
     * @param  array<int, array<string, true>>  $found
     * @param  array<string, true>  $allowed
     */
    private function remember(array &$found, int $keywordId, string $language, array $allowed): void
    {
        if ($keywordId <= 0) {
            return;
        }

        $code = ArticleLanguageCode::normalize($language);
        if ($code === '' || ! isset($allowed[$code])) {
            return;
        }

        $found[$keywordId][$code] = true;
    }
}
