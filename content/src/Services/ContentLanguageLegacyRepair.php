<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Support\ContentLanguageCodeNormalizer;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use Illuminate\Support\Facades\DB;

/**
 * Safe deterministic repair of known language aliases → ISO 639-1.
 * Does not guess from display labels beyond what the normalizer already maps.
 */
final class ContentLanguageLegacyRepair
{
    /**
     * @return array{articles: int, site_metas: int, skipped_unknown: list<string>}
     */
    public function repairKnownAliases(?callable $articleUpdater = null, ?callable $metaUpdater = null): array
    {
        $skipped = [];
        $articlesFixed = 0;
        $metasFixed = 0;

        $articlesFixed = $articleUpdater !== null
            ? (int) $articleUpdater()
            : $this->repairArticlesColumn($skipped);

        $metasFixed = $metaUpdater !== null
            ? (int) $metaUpdater()
            : $this->repairSitePrimaryLanguageMetas($skipped);

        return [
            'articles' => $articlesFixed,
            'site_metas' => $metasFixed,
            'skipped_unknown' => array_values(array_unique($skipped)),
        ];
    }

    /**
     * Known stored variants that should match a canonical code during transition reads.
     *
     * @return list<string>
     */
    public static function knownStoredVariants(string $canonical): array
    {
        $code = ContentLanguageCodeNormalizer::normalize($canonical);
        if ($code === null) {
            return [];
        }

        $variants = [$code, strtoupper($code)];
        foreach (ContentLanguageCodeNormalizer::repairAliasMap() as $alias => $mapped) {
            if ($mapped === $code) {
                $variants[] = $alias;
                $variants[] = str_replace('-', '_', $alias);
                $variants[] = str_replace('_', '-', $alias);
            }
        }

        if ($code === 'vi') {
            $variants = array_merge($variants, ['vi_VN', 'vi-VN', 'VI_VN', 'vn', 'VN']);
        }
        if ($code === 'en') {
            $variants = array_merge($variants, [
                'en_US', 'en-US', 'EN_US',
                'en_GB', 'en-GB', 'EN_GB',
            ]);
        }

        return array_values(array_unique($variants));
    }

    /**
     * @param  list<string>  $skipped
     */
    private function repairArticlesColumn(array &$skipped): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('articles')) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        $fixed = 0;
        try {
            $rows = DB::table('articles')
                ->select(['id', 'language'])
                ->whereNotNull('language')
                ->where('language', '!=', '')
                ->get();
        } catch (\Throwable) {
            return 0;
        }

        foreach ($rows as $row) {
            $raw = (string) ($row->language ?? '');
            $repaired = ContentLanguageCodeNormalizer::repairKnownAlias($raw);
            if ($repaired === null) {
                $normalized = ContentLanguageCodeNormalizer::normalize($raw);
                if ($normalized === null || $normalized === $raw) {
                    if ($raw !== '' && ContentLanguageCodeNormalizer::normalize($raw) !== strtolower(trim($raw))) {
                        $skipped[] = $raw;
                    }
                }

                continue;
            }

            if ($repaired === $raw) {
                continue;
            }

            try {
                DB::table('articles')->where('id', $row->id)->update(['language' => $repaired]);
                $fixed++;
            } catch (\Throwable) {
                // skip row
            }
        }

        return $fixed;
    }

    /**
     * @param  list<string>  $skipped
     */
    private function repairSitePrimaryLanguageMetas(array &$skipped): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('site_metas')) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        $fixed = 0;
        try {
            $rows = DB::table('site_metas')
                ->select(['id', 'meta_value'])
                ->where('meta_key', SitePrimaryLanguageService::META_KEY)
                ->whereNotNull('meta_value')
                ->where('meta_value', '!=', '')
                ->get();
        } catch (\Throwable) {
            return 0;
        }

        foreach ($rows as $row) {
            $raw = (string) ($row->meta_value ?? '');
            $repaired = ContentLanguageCodeNormalizer::repairKnownAlias($raw);
            if ($repaired === null) {
                if ($raw !== '' && ContentLanguageCodeNormalizer::normalize($raw) === null) {
                    $skipped[] = $raw;
                }

                continue;
            }

            if ($repaired === $raw) {
                continue;
            }

            try {
                DB::table('site_metas')->where('id', $row->id)->update(['meta_value' => $repaired]);
                $fixed++;
            } catch (\Throwable) {
                // skip
            }
        }

        return $fixed;
    }
}
