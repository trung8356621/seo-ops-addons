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

final class GscOpportunitiesSliceProvider implements ContextSliceProvider
{
    public function __construct(
        private readonly GscContextSource $source,
    ) {}

    public function definition(): ContextSliceDefinition
    {
        return new ContextSliceDefinition(
            key: ContextSliceKey::GSC_OPPORTUNITIES,
            description: 'Search performance opportunities: rising, falling, CTR, near-page-one, decay, new-content signals.',
            scope: 'site',
            views: [ContextView::Summary->value, ContextView::Standard->value, ContextView::Detail->value],
            defaultView: ContextView::Summary->value,
            optionalParameters: ['period', 'limit'],
            periodAware: true,
        );
    }

    public function provide(ContextSliceRequest $request): ContextSlice
    {
        $period = $request->periodKey() ?? now()->format('Y-m');
        $ctx = $this->source->load($request->siteId, $period);
        $limit = $request->limit(ContextProjection::DEFAULT_LIST_LIMIT[$request->view->value]) ?? 5;
        $summary = $ctx->summary;
        $metrics = $ctx->metrics;

        $data = [
            'period' => $period,
            'counts' => [
                'rising' => (int) ($metrics['rising_count'] ?? 0),
                'falling' => (int) ($metrics['falling_count'] ?? 0),
                'high_impression_low_ctr' => (int) ($metrics['ctr_opportunity_count'] ?? 0),
                'near_page_one' => (int) ($metrics['near_page_one_count'] ?? 0),
                'content_decay' => (int) ($metrics['content_decay_count'] ?? 0),
                'new_content' => (int) ($metrics['new_content_opportunity_count'] ?? 0),
            ],
            'absent' => ($metrics['absent'] ?? false) === true,
        ];

        if ($request->view !== ContextView::Summary) {
            foreach ([
                'rising_queries',
                'falling_queries',
                'high_impression_low_ctr',
                'near_page_one',
                'content_decay',
                'new_content_opportunities',
            ] as $key) {
                $data[$key] = ContextListSlice::fromAll(
                    is_array($summary[$key] ?? null) ? $summary[$key] : [],
                    $limit,
                );
            }
        }

        if ($request->view === ContextView::Detail) {
            $signals = is_array($ctx->context['planning_signals'] ?? null) ? $ctx->context['planning_signals'] : [];
            $data['planning_signals'] = ContextListSlice::fromAll($signals, $limit);
        }

        return ContextSlice::make(
            ContextSliceKey::GSC_OPPORTUNITIES,
            $request->siteId,
            $data,
            $ctx->sourceUpdatedAt,
            $ctx->available,
            $ctx->generatedAt,
            $ctx->stale,
        );
    }
}
