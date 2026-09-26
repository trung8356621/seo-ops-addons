<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Providers;

use App\Models\Site;
use InvalidArgumentException;
use Omnichannel\Addons\Seo\Services\Context\Projection\ContextListSlice;
use Omnichannel\Addons\Seo\Services\Context\Projection\ContextProjection;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceKey;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextView;
use Omnichannel\Addons\Seo\Services\Context\Slice\Contracts\ContextSliceProvider;
use Omnichannel\Addons\Seo\Services\SiteContext\Readers\SiteLinkContextReader;

final class SeoInternalLinksSliceProvider implements ContextSliceProvider
{
    public function __construct(
        private readonly SiteLinkContextReader $links,
    ) {}

    public function definition(): ContextSliceDefinition
    {
        return new ContextSliceDefinition(
            key: ContextSliceKey::SEO_INTERNAL_LINKS,
            description: 'Internal linking stats and link-analysis snapshot counts.',
            scope: 'site',
            views: [ContextView::Summary->value, ContextView::Standard->value, ContextView::Detail->value],
            defaultView: ContextView::Summary->value,
            optionalParameters: ['limit'],
        );
    }

    public function provide(ContextSliceRequest $request): ContextSlice
    {
        $site = Site::query()->find($request->siteId);
        if (! $site instanceof Site) {
            throw new InvalidArgumentException('Site not found.');
        }
        $linking = $this->links->internalLinking($site);
        $snap = $this->links->analysisSnapshot($site);
        $limit = $request->limit(ContextProjection::DEFAULT_LIST_LIMIT[$request->view->value]) ?? 5;
        $topLinked = ContextListSlice::fromAll(
            is_array($linking['top_linked_articles'] ?? null) ? $linking['top_linked_articles'] : [],
            $limit,
        );
        $data = [
            'available' => (bool) ($linking['available'] ?? false),
            'source' => (string) ($linking['source'] ?? 'unavailable'),
            'total_internal_links' => $linking['total_internal_links'] ?? null,
            'linked_articles' => $linking['linked_articles'] ?? null,
            'articles_without_internal_links' => $linking['articles_without_internal_links'] ?? null,
            'broken_links' => array_key_exists('broken_links', $snap) ? (int) $snap['broken_links'] : null,
            'orphan_pages' => array_key_exists('orphan_pages', $snap) ? (int) $snap['orphan_pages'] : null,
            'opportunities' => array_key_exists('opportunities', $snap) ? (int) $snap['opportunities'] : null,
            'last_analyzed_at' => $snap['last_analyzed_at'] ?? null,
            'top_linked_articles' => $topLinked,
        ];
        if ($request->view === ContextView::Detail) {
            $data['internal_linking'] = $linking;
        }

        return ContextSlice::make(
            ContextSliceKey::SEO_INTERNAL_LINKS,
            $request->siteId,
            $data,
            is_string($snap['last_analyzed_at'] ?? null) ? (string) $snap['last_analyzed_at'] : null,
            (bool) ($linking['available'] ?? false),
        );
    }
}
