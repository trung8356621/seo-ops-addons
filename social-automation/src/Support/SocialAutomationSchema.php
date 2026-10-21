<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class SocialAutomationSchema
{
    public static function has(string $table): bool
    {
        try {
            return Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    public static function agentsReady(): bool
    {
        return self::has('sa_agents') && self::has('sa_browser_sessions') && self::has('sa_accounts');
    }

    public static function jobsReady(): bool
    {
        return self::has('sa_tasks') && self::has('sa_jobs');
    }

    public static function schedulesReady(): bool
    {
        return self::has('sa_schedules') && self::has('sa_schedule_triggers');
    }
}
