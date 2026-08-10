<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Illuminate\Support\Facades\Cache;

/**
 * Daily budget counters via cache — warning / exceeded.
 */
final class ContentProjectAgentBudgetGuard
{
    /**
     * @return array{status: string, message?: string}
     */
    public function check(int $tenantId, ?int $siteId = null, string $action = 'step'): array
    {
        $date = now()->toDateString();
        $key = "agent-budget:{$tenantId}:".($siteId ?? 0).":{$action}:{$date}";
        $limit = (int) config('seo-content-ai.content_project_agent.planner.daily_action_budget', 500);
        $count = (int) Cache::get($key, 0);

        if ($count >= $limit) {
            return ['status' => 'exceeded', 'message' => 'Daily action budget exceeded.'];
        }

        if ($count >= (int) ($limit * 0.9)) {
            return ['status' => 'warning', 'message' => 'Approaching daily action budget.'];
        }

        return ['status' => 'ok'];
    }

    public function increment(int $tenantId, ?int $siteId = null, string $action = 'step', int $by = 1): void
    {
        $date = now()->toDateString();
        $key = "agent-budget:{$tenantId}:".($siteId ?? 0).":{$action}:{$date}";
        $ttl = now()->endOfDay()->diffInSeconds(now()) + 60;
        Cache::add($key, 0, $ttl);
        Cache::increment($key, $by);
    }
}
