<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationSchedulerHeartbeat;

final class AutomationSchedulerHeartbeatService
{
    public const NAME_DISPATCH_SCHEDULED = 'dispatch_scheduled';

    public const NAME_RECOVER_STALE = 'recover_stale';

    /**
     * @param  array<string, mixed>  $meta
     */
    public function beat(string $name, array $meta = []): AutomationSchedulerHeartbeat
    {
        /** @var AutomationSchedulerHeartbeat $row */
        $row = AutomationSchedulerHeartbeat::query()->updateOrCreate(
            ['name' => $name],
            [
                'last_beat_at' => now(),
                'meta' => $meta !== [] ? $meta : null,
            ],
        );

        return $row;
    }

    public function lastBeat(string $name): ?AutomationSchedulerHeartbeat
    {
        $row = AutomationSchedulerHeartbeat::query()->where('name', $name)->first();

        return $row instanceof AutomationSchedulerHeartbeat ? $row : null;
    }
}
