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
use Omnichannel\Addons\Seo\Services\GscContext\GscContextSource;

final class GscCannibalizationSliceProvider implements ContextSliceProvider
{
    public function __construct(
        private readonly GscContextSource $source,
    ) {}

    public function definition(): ContextSliceDefinition
    {
        return new ContextSliceDefinition(
            key: ContextSliceKey::GSC_CANNIBALIZATION,
            description: 'Possible query cannibalization signals from persisted GSC facts.',
            scope: 'site',
            views: [ContextView::Summary->value, ContextView::Standard->value, ContextView::Detail->value],
            defaultView: ContextView::Summary->value,
            optionalParameters: ['period', 'period_key', 'limit'],
            periodAware: true,
        );
    }

    public function provide(ContextSliceRequest $request): ContextSlice
    {
        $period = $request->periodKey() ?? now()->format('Y-m');
        $ctx = $this->source->load($request->siteId, $period);
        $limit = $request->limit(ContextProjection::DEFAULT_LIST_LIMIT[$request->view->value]) ?? 5;
        $items = is_array($ctx->summary['possible_cannibalization'] ?? null)
            ? $ctx->summary['possible_cannibalization']
            : [];
        $data = [
            'period' => $period,
            'count' => (int) ($ctx->metrics['possible_cannibalization_count'] ?? count($items)),
            'absent' => ($ctx->metrics['absent'] ?? false) === true,
        ];
        if ($request->view !== ContextView::Summary) {
            $data['items'] = ContextListSlice::fromAll($items, $limit);
        }

        return ContextSlice::make(
            ContextSliceKey::GSC_CANNIBALIZATION,
            $request->siteId,
            $data,
            $ctx->sourceUpdatedAt,
            $ctx->available,
            $ctx->generatedAt,
            $ctx->stale,
        );
    }
}
