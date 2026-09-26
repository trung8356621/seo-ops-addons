<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SiteContext\Readers;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\SiteContext\Aggregators\SiteContentDistributionAggregator;

/**
 * Article / content distribution for Site Intelligence Context.
 */
final class SiteContentContextReader
{
    public function __construct(
        private readonly SiteContentDistributionAggregator $contentDistribution,
        private readonly SitePublishingContextReader $publishing,
    ) {}

    /**
     * @return array{total: int, published: int, draft: int, scheduled: int, private: int, other: int}
     */
    public function articleCounts(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return ['total' => 0, 'published' => 0, 'draft' => 0, 'scheduled' => 0, 'private' => 0, 'other' => 0];
        }
        $base = SeoArticle::query()->where('site_id', $siteId)->where('status', '!=', 'trash');
        $publishing = $this->publishing->status($siteId);

        return [
            'total' => (int) (clone $base)->count(),
            'published' => (int) ($publishing['published'] ?? 0),
            'draft' => (int) ($publishing['draft'] ?? 0),
            'scheduled' => (int) ($publishing['scheduled'] ?? 0),
            'private' => (int) ($publishing['private'] ?? 0),
            'other' => (int) ($publishing['other'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function distribution(int $siteId): array
    {
        return $this->contentDistribution->aggregate($siteId);
    }
}
