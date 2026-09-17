<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use App\Models\Site;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SearchFoundation\Services\SiteLink\SiteLinkPolicyResolver;
use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkCatalog;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;

/**
 * Compatibility adapter for Article Editor Domain Link List.
 *
 * Composition SSOT: {@see SiteLinkPolicyResolver::forArticleEditor()}.
 * Does not persist product_cat into manual prompt rows.
 */
final class EffectiveDomainLinkResolver
{
    public const SOURCE_CUSTOM = 'custom';

    public const SOURCE_PRODUCT_CAT = 'product_cat';

    public const SOURCE_MAIN_DOMAIN = 'main_domain';

    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
        private readonly ?SiteLinkPolicyResolver $policy = null,
    ) {}

    /**
     * @return list<array{
     *     keyword: string,
     *     link: string,
     *     source: string,
     *     priority: int
     * }>
     */
    public function forSite(Site $site): array
    {
        return $this->toLegacyShape($this->policy()->forArticleEditor($site));
    }

    /**
     * @param  list<array{keyword?: string, link?: string, url?: string}>  $custom
     * @param  list<array{keyword?: string, link?: string, url?: string}>  $productCat
     * @param  array{keyword?: string, link?: string, url?: string}|null  $main
     * @return list<array{keyword: string, link: string, source: string, priority: int}>
     */
    public function merge(array $custom, array $productCat, ?array $main): array
    {
        $normalize = static function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $keyword = trim((string) ($row['keyword'] ?? ''));
                $url = trim((string) ($row['url'] ?? $row['link'] ?? ''));
                if ($keyword === '' || $url === '') {
                    continue;
                }
                $out[] = ['keyword' => $keyword, 'url' => $url];
            }

            return $out;
        };

        $mainRecord = null;
        if (is_array($main)) {
            $keyword = trim((string) ($main['keyword'] ?? ''));
            $url = trim((string) ($main['url'] ?? $main['link'] ?? ''));
            if ($keyword !== '' && $url !== '') {
                $mainRecord = ['keyword' => $keyword, 'url' => $url];
            }
        }

        return $this->toLegacyShape(
            $this->policy()->compose($normalize($custom), $normalize($productCat), $mainRecord),
        );
    }

    public static function normalizeKeywordKey(string $keyword): string
    {
        return SiteLinkPolicyResolver::normalizeKeywordKey($keyword);
    }

    /**
     * @return array{
     *     wordpress_active: int,
     *     manual: int,
     *     product_categories: int,
     *     effective: int,
     *     main_domain: int,
     *     label: string
     * }
     */
    public function catalogSummary(Site $site): array
    {
        $siteId = (int) $site->getKey();
        $effective = $this->forSite($site);

        $manual = 0;
        $productCategories = 0;
        $mainDomain = 0;
        foreach ($effective as $row) {
            $source = (string) ($row['source'] ?? '');
            if ($source === self::SOURCE_CUSTOM) {
                $manual++;
            } elseif ($source === self::SOURCE_PRODUCT_CAT) {
                $productCategories++;
            } elseif ($source === self::SOURCE_MAIN_DOMAIN) {
                $mainDomain++;
            }
        }

        $wordpressActive = SeoSiteLinkCatalog::query()
            ->forSite($siteId)
            ->where('source', SiteSyncSchema::SOURCE_WORDPRESS)
            ->whereNull('inactive_at')
            ->count();

        return [
            'wordpress_active' => $wordpressActive,
            'manual' => $manual,
            'product_categories' => $productCategories,
            'effective' => count($effective),
            'main_domain' => $mainDomain,
            'label' => sprintf(
                'WordPress active: %d · Manual: %d · Product categories: %d · Effective links: %d.',
                $wordpressActive,
                $manual,
                $productCategories,
                count($effective),
            ),
        ];
    }

    private function policy(): SiteLinkPolicyResolver
    {
        if ($this->policy instanceof SiteLinkPolicyResolver) {
            return $this->policy;
        }

        try {
            if (function_exists('app')) {
                return app(SiteLinkPolicyResolver::class);
            }
        } catch (\Throwable) {
            // Fall through to local construction for unit tests.
        }

        return new SiteLinkPolicyResolver(
            $this->promptContext,
            new \Omnichannel\Addons\SearchFoundation\Services\SiteLink\VerifiedProductCatLinkSource,
        );
    }

    /**
     * @param  list<array{keyword: string, url: string, source: string}>  $records
     * @return list<array{keyword: string, link: string, source: string, priority: int}>
     */
    private function toLegacyShape(array $records): array
    {
        $out = [];
        foreach ($records as $row) {
            $policySource = (string) ($row['source'] ?? '');
            [$legacySource, $priority] = match ($policySource) {
                SiteLinkPolicyResolver::SOURCE_DOMAIN_LINK_LIST => [self::SOURCE_CUSTOM, 1],
                SiteLinkPolicyResolver::SOURCE_PRODUCT_CAT => [self::SOURCE_PRODUCT_CAT, 2],
                SiteLinkPolicyResolver::SOURCE_MAIN_DOMAIN => [self::SOURCE_MAIN_DOMAIN, 3],
                default => [self::SOURCE_CUSTOM, 1],
            };

            $out[] = [
                'keyword' => (string) $row['keyword'],
                'link' => (string) $row['url'],
                'source' => $legacySource,
                'priority' => $priority,
            ];
        }

        return $out;
    }
}
