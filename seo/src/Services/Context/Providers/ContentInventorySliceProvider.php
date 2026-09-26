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

final class ContentInventorySliceProvider implements ContextSliceProvider
{
    public function __construct(
        private readonly SiteContentContextReader $content,
    ) {}

    public function definition(): ContextSliceDefinition
    {
        return new ContextSliceDefinition(
            key: ContextSliceKey::CONTENT_INVENTORY,
            description: 'Article inventory counts by publishing status.',
            scope: 'site',
            views: [ContextView::Summary->value, ContextView::Standard->value, ContextView::Detail->value],
            defaultView: ContextView::Summary->value,
        );
    }

    public function provide(ContextSliceRequest $request): ContextSlice
    {
        $counts = $this->content->articleCounts($request->siteId);
        $data = $request->view === ContextView::Summary
            ? [
                'total' => $counts['total'],
                'published' => $counts['published'],
            ]
            : $counts;

        return ContextSlice::make(
            ContextSliceKey::CONTENT_INVENTORY,
            $request->siteId,
            $data,
            null,
            true,
            null,
            false,
        );
    }
}
