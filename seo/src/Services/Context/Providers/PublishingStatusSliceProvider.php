<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Providers;

use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceKey;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextView;
use Omnichannel\Addons\Seo\Services\Context\Slice\Contracts\ContextSliceProvider;
use Omnichannel\Addons\Seo\Services\SiteContext\Readers\SitePublishingContextReader;

final class PublishingStatusSliceProvider implements ContextSliceProvider
{
    public function __construct(
        private readonly SitePublishingContextReader $publishing,
    ) {}

    public function definition(): ContextSliceDefinition
    {
        return new ContextSliceDefinition(
            key: ContextSliceKey::PUBLISHING_STATUS,
            description: 'Publishing status counts for site articles.',
            scope: 'site',
            views: [ContextView::Summary->value, ContextView::Standard->value, ContextView::Detail->value],
            defaultView: ContextView::Summary->value,
        );
    }

    public function provide(ContextSliceRequest $request): ContextSlice
    {
        $status = $this->publishing->status($request->siteId);
        $data = $request->view === ContextView::Summary
            ? [
                'published' => $status['published'],
                'draft' => $status['draft'],
                'scheduled' => $status['scheduled'],
            ]
            : $status;

        return ContextSlice::make(
            ContextSliceKey::PUBLISHING_STATUS,
            $request->siteId,
            $data,
            null,
            true,
            null,
            false,
        );
    }
}
