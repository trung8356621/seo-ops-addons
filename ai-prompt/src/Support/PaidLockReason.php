<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Reasons for api_connections.paid_locked (paid lane only).
 * Multiple reasons may be active; paid_locked === reasons not empty.
 */
enum PaidLockReason: string
{
    case ManualFreeOnly = 'manual_free_only';
    case BudgetLimited = 'budget_limited';
    case AdminLock = 'admin_lock';
}
