<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * One active job per session; V1 one active job per agent.
 */
final class SessionLockPolicy
{
    /**
     * @param  array{locked_job_id?: string|int|null, lock_expires_at?: \DateTimeInterface|null}  $session
     */
    public function canLock(array $session, string|int $jobId, ?\DateTimeInterface $now = null): bool
    {
        $locked = $session['locked_job_id'] ?? null;
        if ($locked === null || $locked === '' || (string) $locked === (string) $jobId) {
            return true;
        }

        $expires = $session['lock_expires_at'] ?? null;
        if ($expires instanceof \DateTimeInterface) {
            $nowTs = ($now ?? new \DateTimeImmutable('now'))->getTimestamp();

            return $expires->getTimestamp() <= $nowTs;
        }

        return false;
    }

    /**
     * @param  list<array{status: string}>  $agentJobs
     */
    public function agentHasActiveJob(array $agentJobs): bool
    {
        foreach ($agentJobs as $job) {
            $status = (string) ($job['status'] ?? '');
            if (in_array($status, ['reserved', 'claimed', 'running', 'unknown'], true)) {
                return true;
            }
        }

        return false;
    }
}
