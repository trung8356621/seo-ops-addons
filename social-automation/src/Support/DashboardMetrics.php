<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaTask;
use Throwable;

/**
 * Real counts from models — empty/zero when schema unavailable (no fake KPI).
 */
final class DashboardMetrics
{
    public function __construct(
        private readonly OnlineStateCalculator $online = new OnlineStateCalculator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $schemaReady = SocialAutomationSchema::agentsReady();

        return [
            'schema_ready' => $schemaReady,
            'packages' => $this->safeCount(static fn (): int => SocialAutomationSchema::has('sa_packages')
                ? (int) SaPackage::query()->count() : 0),
            'agents_total' => $this->safeCount(static fn (): int => $schemaReady ? (int) SaAgent::query()->count() : 0),
            'agents_online' => $this->countOnlineAgents(),
            'sessions_ready' => $this->safeCount(static fn (): int => $schemaReady
                ? (int) SaBrowserSession::query()->where('ready_status', 'ready')->count() : 0),
            'tasks_active' => $this->safeCount(static fn (): int => SocialAutomationSchema::jobsReady()
                ? (int) SaTask::query()->where('status', 'active')->count() : 0),
            'jobs_running' => $this->safeCount(static fn (): int => SocialAutomationSchema::jobsReady()
                ? (int) SaJob::query()->whereIn('status', ['reserved', 'claimed', 'running', 'unknown'])->count() : 0),
            'jobs_completed' => $this->safeCount(static fn (): int => SocialAutomationSchema::jobsReady()
                ? (int) SaJob::query()->where('status', 'completed')->count() : 0),
            'empty' => ! $schemaReady,
        ];
    }

    private function countOnlineAgents(): int
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return 0;
        }
        try {
            $n = 0;
            foreach (SaAgent::query()->get(['last_heartbeat_at']) as $agent) {
                if ($this->online->isOnline($agent->last_heartbeat_at)) {
                    $n++;
                }
            }

            return $n;
        } catch (Throwable) {
            return 0;
        }
    }

    /** @param  callable(): int  $fn */
    private function safeCount(callable $fn): int
    {
        try {
            return $fn();
        } catch (Throwable) {
            return 0;
        }
    }
}
