<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Seo\Services\Notifications\Publishers\RunnerHealthNotificationPublisher;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Models\User;
use Illuminate\Console\Command;

final class CheckOperationalRunnerHealthCommand extends Command
{
    protected $signature = 'seo:notifications:check-runner-health
        {--tenant= : Optional tenant owner user id}';

    protected $description = 'Evaluate scheduler/queue runner heartbeats and emit operational notifications.';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        RunnerHealthNotificationPublisher $runnerHealth,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();

        $tenantOpt = $this->option('tenant');
        $tenants = [];
        if ($tenantOpt !== null && $tenantOpt !== '') {
            $tenants = [(int) $tenantOpt];
        } else {
            $tenants = User::query()
                ->where('status', User::STATUS_NORMAL)
                ->where('seo_role', User::SEO_ROLE_MANAGER)
                ->where(function ($query): void {
                    $query->whereNull('parent_id')->orWhere('parent_id', 0);
                })
                ->orderBy('id')
                ->limit(50)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        $pending = 0;
        try {
            $pending = (int) SeoProjectTask::query()
                ->whereNull('archived_at')
                ->where(function ($query): void {
                    $query->where('publish_queue_status', 'like', '%retry%')
                        ->orWhereNotNull('scheduled_publish_at');
                })
                ->count();
        } catch (\Throwable) {
            $pending = 0;
        }

        foreach ($tenants as $tenantId) {
            $results = $runnerHealth->checkAll($tenantId, null, $pending);
            foreach ($results as $result) {
                $this->line(sprintf('tenant=%d runner=%s status=%s', $tenantId, $result['runner'], $result['status']));
            }
        }

        return self::SUCCESS;
    }
}
