<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Data;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationEventDispatchOutcome;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;

final class AutomationEventDispatchResult
{
    public function __construct(
        public readonly AutomationEventDispatchOutcome $outcome,
        public readonly ?BusinessEvent $event = null,
        public readonly ?string $message = null,
        public readonly ?string $errorCode = null,
        public readonly int $matchedRules = 0,
    ) {}

    public function isSkippedNoRule(): bool
    {
        return $this->outcome === AutomationEventDispatchOutcome::SkippedNoRule;
    }

    public function isQueued(): bool
    {
        return $this->outcome === AutomationEventDispatchOutcome::Queued;
    }

    public function isRejectedOrInvalid(): bool
    {
        return in_array($this->outcome, [
            AutomationEventDispatchOutcome::RejectedInvalidPayload,
            AutomationEventDispatchOutcome::FailedToDispatch,
            AutomationEventDispatchOutcome::BlockedLoop,
        ], true);
    }

    public function isSuccessPath(): bool
    {
        return in_array($this->outcome, [
            AutomationEventDispatchOutcome::Queued,
            AutomationEventDispatchOutcome::SkippedNoRule,
            AutomationEventDispatchOutcome::SkippedRuleDisabled,
            AutomationEventDispatchOutcome::Deduplicated,
        ], true);
    }
}
