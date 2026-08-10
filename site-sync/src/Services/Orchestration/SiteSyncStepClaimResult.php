<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Orchestration;

enum SiteSyncStepClaimResult: string
{
    case Claimed = 'claimed';
    case AlreadyCompleted = 'already_completed';
    case OwnedByOtherWorker = 'owned_by_other_worker';
    case StaleLock = 'stale_lock';
    case InvalidRunState = 'invalid_run_state';
    case MissingRun = 'missing_run';
    case TenantMismatch = 'tenant_mismatch';
    case Cancelled = 'cancelled';
}
