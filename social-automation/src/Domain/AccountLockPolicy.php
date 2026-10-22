<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Platform-account exclusivity: one active job per account_id (not per browser session).
 *
 * @phpstan-type JobRow array{account_id?: string|int|null, status?: string, job_id?: string|int|null}
 */
final class AccountLockPolicy
{
    /** @var list<string> */
    public const ACTIVE_STATUSES = ['reserved', 'claimed', 'running', 'unknown'];

    /**
     * @param  list<JobRow>  $activeJobsForAccount
     */
    public function canAssign(string|int|null $accountId, array $activeJobsForAccount, string|int|null $exceptJobId = null): bool
    {
        if ($accountId === null || $accountId === '' || (int) $accountId <= 0) {
            // No strong account binding — caller must already have rejected incomplete identity.
            return false;
        }

        foreach ($activeJobsForAccount as $job) {
            $status = (string) ($job['status'] ?? '');
            if (! in_array($status, self::ACTIVE_STATUSES, true)) {
                continue;
            }
            if ($exceptJobId !== null && (string) ($job['job_id'] ?? '') === (string) $exceptJobId) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @param  list<string|int>  $busyAccountIds
     */
    public function isAccountBusy(string|int $accountId, array $busyAccountIds): bool
    {
        foreach ($busyAccountIds as $id) {
            if ((string) $id === (string) $accountId) {
                return true;
            }
        }

        return false;
    }
}
