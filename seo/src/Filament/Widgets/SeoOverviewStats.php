<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Widgets;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Seo\Filament\Concerns\InteractsWithSeoDashboardSite;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;
use App\Models\TaskJob;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SeoOverviewStats extends StatsOverviewWidget
{
    use InteractsWithSeoDashboardSite;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return \Omnichannel\Addons\Seo\Support\SeoAccessControl::hasGlobalSiteScope();
    }

    protected function getStats(): array
    {
        $siteId = $this->resolveDashboardSiteId();
        if ($siteId === null) {
            return [
                Stat::make(__('seo-content-ai::filament.dashboard.select_domain'), '—')
                    ->description(__('seo-content-ai::filament.dashboard.select_domain_hint'))
                    ->icon('heroicon-o-globe-alt')
                    ->color('gray'),
            ];
        }

        $overview = app(DomainOverviewService::class);
        $sync = $overview->getSyncStatistics($siteId);
        $scoring = $overview->getScoringStatistics($siteId);

        $keywordTotal = Keyword::query()->forSite($siteId)->count();
        $keywordFocus = Keyword::query()
            ->forSite($siteId)
            ->whereHas(
                'mainArticles',
                static fn ($query) => $query->where('articles.site_id', $siteId),
            )
            ->count();
        $keywordInternal = Keyword::query()
            ->forSite($siteId)
            ->whereHas(
                'linkMaps',
                static fn ($query) => $query
                    ->where('link_type', SeoLinkMapType::Internal)
                    ->whereHas(
                        'sourceArticle',
                        static fn ($articleQuery) => $articleQuery->where('site_id', $siteId),
                    ),
            )
            ->count();

        $avgScore = $scoring['avg_score'];
        $scoreColor = match (true) {
            $avgScore === null => 'gray',
            $avgScore < 50 => 'danger',
            $avgScore < 70 => 'warning',
            default => 'success',
        };

        $queueCount = $this->countActiveAiQueue($siteId);

        return [
            Stat::make(__('seo-content-ai::filament.dashboard.stat_keywords'), (string) $keywordTotal)
                ->description(__('seo-content-ai::filament.dashboard.stat_keywords_desc', [
                    'focus' => $keywordFocus,
                    'internal' => $keywordInternal,
                ]))
                ->icon('heroicon-o-key')
                ->color('primary'),

            Stat::make(__('seo-content-ai::filament.dashboard.stat_synced'), (string) $sync['total'])
                ->description(__('seo-content-ai::filament.dashboard.stat_synced_desc', [
                    'posts' => $sync['articles'],
                    'products' => $sync['products'],
                ]))
                ->icon('heroicon-o-arrow-path')
                ->color('info'),

            Stat::make(__('seo-content-ai::filament.dashboard.stat_seo_health'), $avgScore !== null ? number_format($avgScore, 1) : '—')
                ->description(__('seo-content-ai::filament.dashboard.stat_seo_health_desc', [
                    'scored' => $scoring['scored'],
                ]))
                ->icon('heroicon-o-chart-bar')
                ->color($scoreColor),

            Stat::make(__('seo-content-ai::filament.dashboard.stat_ai_queue'), (string) $queueCount)
                ->description(__('seo-content-ai::filament.dashboard.stat_ai_queue_desc'))
                ->icon('heroicon-o-cpu-chip')
                ->color($queueCount > 0 ? 'warning' : 'success'),
        ];
    }

    private function countActiveAiQueue(int $siteId): int
    {
        $taskJobs = TaskJob::query()
            ->where('site_id', $siteId)
            ->whereIn('status', ['pending', 'running'])
            ->count();

        $projectRuns = SeoProjectRun::query()
            ->where('status', SeoProjectRun::STATUS_RUNNING)
            ->whereHas('project', static fn ($query) => $query->where('site_id', $siteId))
            ->count();

        $runningProjects = SeoProject::query()
            ->where('site_id', $siteId)
            ->where('status', SeoProject::STATUS_RUNNING)
            ->count();

        $processingMedia = SeoMedia::query()
            ->where('site_id', $siteId)
            ->where('status', 'processing')
            ->count();

        return $taskJobs + $projectRuns + $runningProjects + $processingMedia;
    }
}
