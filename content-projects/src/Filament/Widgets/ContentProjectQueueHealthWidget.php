<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Widgets;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Publishing Queue Health — list header.
 */
final class ContentProjectQueueHealthWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return SeoAccessControl::canMutateContentProjects()
            || SeoAccessControl::canViewProjectArchives();
    }

    protected function getStats(): array
    {
        $siteIds = SeoAccessControl::accessibleSiteIds();
        $connectionId = null;
        $current = \Omnichannel\Addons\Seo\Support\SeoConnectionContext::current();
        if ($current instanceof \App\Models\SeoDatabaseConnection) {
            $connectionId = (int) $current->getKey();
        }
        $health = app(ContentProjectQueueHealthService::class)->snapshot(
            $siteIds !== [] ? $siteIds : null,
            $connectionId,
        );

        return [
            Stat::make(__('seo-content-ai::filament.projects.health_waiting'), (string) $health['waiting'])
                ->description(__('seo-content-ai::filament.projects.health_last_worker', [
                    'at' => $health['last_worker_run'] ?? '—',
                ]))
                ->color('primary'),
            Stat::make(__('seo-content-ai::filament.projects.health_processing'), (string) $health['processing'])
                ->description(__('seo-content-ai::filament.projects.health_last_success', [
                    'at' => $health['last_success'] ?? '—',
                ]))
                ->color('warning'),
            Stat::make(__('seo-content-ai::filament.projects.health_failed'), (string) $health['failed'])
                ->description(__('seo-content-ai::filament.projects.health_last_failure', [
                    'at' => $health['last_failure'] ?? '—',
                ]))
                ->color('danger'),
            Stat::make(__('seo-content-ai::filament.projects.health_retrying'), (string) $health['retrying'])
                ->color('gray'),
        ];
    }
}
