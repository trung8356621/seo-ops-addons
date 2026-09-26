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

final class GscPerformanceSliceProvider implements ContextSliceProvider
{
    public function __construct(
        private readonly GscContextSource $source,
    ) {}

    public function definition(): ContextSliceDefinition
    {
        return new ContextSliceDefinition(
            key: ContextSliceKey::GSC_PERFORMANCE,
            description: 'GSC totals, period comparison, top queries and top pages.',
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
        $summary = $ctx->summary;
        $data = [
            'period' => $summary['period'] ?? ['current' => $period],
            'totals' => $summary['totals'] ?? null,
            'comparison' => $summary['comparison'] ?? null,
            'clicks' => $ctx->metrics['clicks'] ?? 0,
            'impressions' => $ctx->metrics['impressions'] ?? 0,
            'absent' => ($ctx->metrics['absent'] ?? false) === true,
        ];
        if ($request->view !== ContextView::Summary) {
            $data['top_queries'] = ContextListSlice::fromAll(
                is_array($summary['top_queries'] ?? null) ? $summary['top_queries'] : [],
                $limit,
            );
            $data['top_pages'] = ContextListSlice::fromAll(
                is_array($summary['top_pages'] ?? null) ? $summary['top_pages'] : [],
                $limit,
            );
            $data['identity'] = $summary['identity'] ?? [];
        }
        if ($request->view === ContextView::Detail) {
            $data['metrics'] = $ctx->metrics;
            $data['previous_totals'] = $summary['previous_totals'] ?? null;
        }

        return ContextSlice::make(
            ContextSliceKey::GSC_PERFORMANCE,
            $request->siteId,
            $data,
            $ctx->sourceUpdatedAt,
            $ctx->available,
            $ctx->generatedAt,
            $ctx->stale,
        );
    }
}
