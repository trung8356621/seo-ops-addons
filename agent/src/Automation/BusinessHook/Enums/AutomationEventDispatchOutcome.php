<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

/**
 * Kết quả dispatch business event — không dùng boolean mơ hồ.
 */
enum AutomationEventDispatchOutcome: string
{
    case Queued = 'QUEUED';
    case SkippedNoRule = 'SKIPPED_NO_RULE';
    case SkippedRuleDisabled = 'SKIPPED_RULE_DISABLED';
    case RejectedInvalidPayload = 'REJECTED_INVALID_PAYLOAD';
    case FailedToDispatch = 'FAILED_TO_DISPATCH';
    case Deduplicated = 'DEDUPLICATED';
    case BlockedLoop = 'BLOCKED_LOOP';
}
