<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Illuminate\Support\Facades\RateLimiter;

final class AutomationRateLimitGuard
{
    public function __construct(
        private readonly AutomationActionRegistry $actionRegistry,
    ) {}

    /**
     * @return array{allowed: bool, retry_after_seconds: int}
     */
    public function check(string $actionCode, ?int $siteId = null): array
    {
        try {
            $definition = $this->actionRegistry->get($actionCode);
        } catch (\Throwable) {
            return ['allowed' => true, 'retry_after_seconds' => 0];
        }

        $keyTemplate = trim((string) ($definition->rateLimitKey ?? ''));
        $maxPerMinute = (int) ($definition->maxAttemptsPerMinute ?? 0);
        if ($keyTemplate === '' || $maxPerMinute <= 0) {
            return ['allowed' => true, 'retry_after_seconds' => 0];
        }

        $limiterKey = 'automation:rate:'.$keyTemplate.':'.($siteId ?? 'global');
        if (RateLimiter::tooManyAttempts($limiterKey, $maxPerMinute)) {
            return [
                'allowed' => false,
                'retry_after_seconds' => max(1, RateLimiter::availableIn($limiterKey)),
            ];
        }

        RateLimiter::hit($limiterKey, 60);

        return ['allowed' => true, 'retry_after_seconds' => 0];
    }
}
