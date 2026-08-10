<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services;

/**
 * Config-driven quotas for Agent Automations (not UI constants).
 */
final class AgentAutomationQuotaService
{
    public function __construct(
        private readonly int $maxActivePerSite = 20,
        private readonly int $maxPerUser = 40,
        private readonly int $maxRunsPerHour = 30,
        private readonly int $maxRunsPerDay = 200,
        private readonly int $maxConcurrentRuns = 3,
        private readonly int $maxNotificationsPerHour = 40,
        private readonly int $maxPlanningCallsPerDay = 50,
        private readonly int $maxReadCallsPerHour = 120,
    ) {}

    public function maxActivePerSite(): int
    {
        return $this->maxActivePerSite;
    }

    public function maxPerUser(): int
    {
        return $this->maxPerUser;
    }

    public function maxRunsPerHour(): int
    {
        return $this->maxRunsPerHour;
    }

    public function maxRunsPerDay(): int
    {
        return $this->maxRunsPerDay;
    }

    public function maxConcurrentRuns(): int
    {
        return $this->maxConcurrentRuns;
    }

    public function maxNotificationsPerHour(): int
    {
        return $this->maxNotificationsPerHour;
    }

    public function maxPlanningCallsPerDay(): int
    {
        return $this->maxPlanningCallsPerDay;
    }

    public function maxReadCallsPerHour(): int
    {
        return $this->maxReadCallsPerHour;
    }
}
