<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Widgets;

use Omnichannel\Addons\Seo\Filament\Concerns\InteractsWithSeoDashboardSite;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;
use Filament\Widgets\Widget;

class SeoScoreChart extends Widget
{
    use InteractsWithSeoDashboardSite;

    protected static string $view = 'seo-content-ai::filament.widgets.seo-score-chart';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = [
        'default' => 12,
        'xl' => 8,
    ];

    public static function canView(): bool
    {
        return \Omnichannel\Addons\Seo\Support\SeoAccessControl::hasGlobalSiteScope();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $siteId = $this->resolveDashboardSiteId();
        if ($siteId === null) {
            return [
                'has_data' => false,
                'scoring' => [
                    'scored' => 0,
                    'avg_score' => null,
                    'min_score' => null,
                    'max_score' => null,
                ],
                'segments' => [],
                'donut_gradient' => '',
            ];
        }

        $overview = app(DomainOverviewService::class);
        $distribution = $overview->getScoreDistribution($siteId);
        $scoring = $overview->getScoringStatistics($siteId);

        $segments = collect($distribution['segments'] ?? [])
            ->map(function (array $segment) use ($overview, $siteId): array {
                if (($segment['count'] ?? 0) > 0) {
                    $segment['filter_url'] = $overview->buildArticlesFilterUrl(
                        $siteId,
                        (string) ($segment['key'] ?? ''),
                    );
                }

                return $segment;
            })
            ->all();

        $chartSegments = array_values(array_filter(
            $segments,
            static fn (array $segment): bool => ($segment['count'] ?? 0) > 0,
        ));

        $donutTotal = array_sum(array_column($chartSegments, 'count'));
        $donutGradient = '';

        if ($donutTotal > 0) {
            $cursor = 0.0;
            $parts = [];
            foreach ($chartSegments as $segment) {
                $pct = ($segment['count'] / $donutTotal) * 100;
                $start = $cursor;
                $cursor += $pct;
                $parts[] = ($segment['color'] ?? '#94a3b8').' '.$start.'% '.$cursor.'%';
            }
            $donutGradient = 'conic-gradient('.implode(', ', $parts).')';
        }

        return [
            'has_data' => ($scoring['scored'] ?? 0) > 0,
            'scoring' => $scoring,
            'segments' => $segments,
            'donut_gradient' => $donutGradient,
        ];
    }
}
