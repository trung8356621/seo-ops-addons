<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Filament\Widgets;

use Omnichannel\Addons\Seo\Filament\Concerns\InteractsWithSeoDashboardSite;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Filament\Widgets\Widget;

class WpSyncStatusTable extends Widget
{
    use InteractsWithSeoDashboardSite;

    protected static string $view = 'seo-content-ai::filament.widgets.wp-sync-status-table';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = [
        'default' => 12,
        'xl' => 4,
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
        $site = $this->resolveDashboardSite();
        if ($site === null) {
            return [
                'has_site' => false,
            ];
        }

        $siteId = (int) $site->getKey();
        $overview = app(DomainOverviewService::class);
        $sync = $overview->getSyncStatistics($siteId);
        $bridge = $overview->getBridgeConnectionStatus($site);

        $runningProjects = SeoProject::query()
            ->where('site_id', $siteId)
            ->where('status', SeoProject::STATUS_RUNNING)
            ->count();

        $runningWorkflows = SeoProjectRun::query()
            ->where('status', SeoProjectRun::STATUS_RUNNING)
            ->whereHas('project', static fn ($query) => $query->where('site_id', $siteId))
            ->count();

        return [
            'has_site' => true,
            'domain' => (string) $site->domain,
            'sync' => $sync,
            'bridge' => $bridge,
            'running_projects' => $runningProjects,
            'running_workflows' => $runningWorkflows,
            'domain_url' => SeoConnectionContext::panelUrl('domains/'.$siteId.'/general'),
            'projects_url' => SeoConnectionContext::panelUrl('content-projects'),
        ];
    }
}
