<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Events;

use Omnichannel\Addons\Agent\Automation\Contracts\AutomationEventDispatcher;
use Omnichannel\Addons\Agent\Automation\Data\EventEnvelope;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2: log-only dispatcher. Không migrate event bus cũ.
 */
final class LoggingAutomationEventDispatcher implements AutomationEventDispatcher
{
    public function dispatch(EventEnvelope $event): void
    {
        Log::info('automation.event', $event->toArray());
    }
}
