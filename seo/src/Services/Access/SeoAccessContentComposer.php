<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Access;

use Omnichannel\Addons\Seo\Services\SiteContext\Aggregators\SiteContentDistributionAggregator;

/**
 * High-signal content distribution for SEO Access /content.
 */
class SeoAccessContentComposer
{
    public const SCHEMA = 'seo.access.content.v1';

    public function __construct(
        private readonly SiteContentDistributionAggregator $distribution,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compose(int $siteId): array
    {
        $raw = $this->distribution->aggregate($siteId);

        return [
            'schema' => self::SCHEMA,
            'site_ref' => 'site:'.$siteId,
            'generated_at' => now()->toIso8601String(),
            'distribution' => [
                'posts' => $raw['posts'] ?? null,
                'pages' => $raw['pages'] ?? null,
                'categories' => $raw['categories'] ?? null,
                'products' => $raw['products'] ?? null,
                'product_categories' => $raw['product_categories'] ?? null,
                'other' => $raw['other'] ?? null,
                'available' => (bool) ($raw['available'] ?? false),
            ],
        ];
    }
}
