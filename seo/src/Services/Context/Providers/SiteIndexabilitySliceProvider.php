<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Providers;

use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceKey;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextView;
use Omnichannel\Addons\Seo\Services\Context\Slice\Contracts\ContextSliceProvider;
use Omnichannel\Addons\Seo\Services\SiteContext\Readers\SiteSeoHealthReader;

final class SiteIndexabilitySliceProvider implements ContextSliceProvider
{
    public function __construct(
        private readonly SiteSeoHealthReader $seoHealth,
    ) {}

    public function definition(): ContextSliceDefinition
    {
        return new ContextSliceDefinition(
            key: ContextSliceKey::SITE_INDEXABILITY,
            description: 'Indexable vs noindex article profile counts for the site.',
            scope: 'site',
            views: [ContextView::Summary->value, ContextView::Standard->value, ContextView::Detail->value],
            defaultView: ContextView::Summary->value,
        );
    }

    public function provide(ContextSliceRequest $request): ContextSlice
    {
        $indexability = $this->seoHealth->indexability($request->siteId);

        return ContextSlice::make(
            ContextSliceKey::SITE_INDEXABILITY,
            $request->siteId,
            [
                'indexable' => $indexability['indexable'],
                'noindex' => $indexability['noindex'],
            ],
            null,
            true,
            null,
            false,
        );
    }
}
