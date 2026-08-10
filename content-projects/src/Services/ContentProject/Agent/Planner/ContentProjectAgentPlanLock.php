<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final class ContentProjectAgentPlanLock
{
    public function acquire(string $planRef, int $seconds = 120): ?Lock
    {
        $lock = Cache::lock('plan:'.$planRef, max(1, $seconds));

        return $lock->get() ? $lock : null;
    }

    public function release(?Lock $lock): void
    {
        $lock?->release();
    }
}
