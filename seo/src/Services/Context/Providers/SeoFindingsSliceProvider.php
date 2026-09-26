<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Providers;

use Omnichannel\Addons\Seo\Services\Context\Projection\ContextListSlice;
use Omnichannel\Addons\Seo\Services\Context\Projection\ContextProjection;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceKey;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextView;
use Omnichannel\Addons\Seo\Services\Context\Slice\Contracts\ContextSliceProvider;
use Omnichannel\Addons\Seo\Services\SiteContext\Readers\SiteSeoHealthReader;

final class SeoFindingsSliceProvider implements ContextSliceProvider
{
    public function __construct(
        private readonly SiteSeoHealthReader $seoHealth,
    ) {}

    public function definition(): ContextSliceDefinition
    {
        return new ContextSliceDefinition(
            key: ContextSliceKey::SEO_FINDINGS,
            description: 'Open SEO findings with severity counts and top items.',
            scope: 'site',
            views: [ContextView::Summary->value, ContextView::Standard->value, ContextView::Detail->value],
            defaultView: ContextView::Summary->value,
            optionalParameters: ['limit'],
        );
    }

    public function provide(ContextSliceRequest $request): ContextSlice
    {
        $findings = $this->seoHealth->findings($request->siteId);
        $limit = $request->limit(ContextProjection::DEFAULT_LIST_LIMIT[$request->view->value]);
        $top = ContextListSlice::fromAll(
            is_array($findings['top'] ?? null) ? $findings['top'] : [],
            $limit ?? 5,
        );
        $data = [
            'critical' => $findings['critical'],
            'high' => $findings['high'],
            'top' => $top,
        ];

        return ContextSlice::make(
            ContextSliceKey::SEO_FINDINGS,
            $request->siteId,
            $data,
            is_string($findings['updated_at'] ?? null) ? (string) $findings['updated_at'] : null,
        );
    }
}
