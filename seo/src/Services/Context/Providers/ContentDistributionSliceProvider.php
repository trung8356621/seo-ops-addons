<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Providers;

use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceKey;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextView;
use Omnichannel\Addons\Seo\Services\Context\Slice\Contracts\ContextSliceProvider;
use Omnichannel\Addons\Seo\Services\SiteContext\Readers\SiteContentContextReader;

final class ContentDistributionSliceProvider implements ContextSliceProvider
{
    public function __construct(
        private readonly SiteContentContextReader $content,
    ) {}

    public function definition(): ContextSliceDefinition
    {
        return new ContextSliceDefinition(
            key: ContextSliceKey::CONTENT_DISTRIBUTION,
            description: 'Content-type distribution from Site Sync / WP manifest.',
            scope: 'site',
            views: [ContextView::Summary->value, ContextView::Standard->value, ContextView::Detail->value],
            defaultView: ContextView::Summary->value,
        );
    }

    public function provide(ContextSliceRequest $request): ContextSlice
    {
        $distribution = $this->content->distribution($request->siteId);
        $available = (bool) ($distribution['available'] ?? false);
        if ($request->view === ContextView::Summary) {
            $data = [
                'available' => $available,
                'posts' => $distribution['posts'] ?? null,
                'pages' => $distribution['pages'] ?? null,
                'categories' => $distribution['categories'] ?? null,
            ];
        } else {
            $data = $distribution;
        }

        return ContextSlice::make(
            ContextSliceKey::CONTENT_DISTRIBUTION,
            $request->siteId,
            $data,
            null,
            $available,
            null,
            false,
        );
    }
}
