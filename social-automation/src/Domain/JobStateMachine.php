<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use Omnichannel\Addons\SocialAutomation\Enums\JobStatus;

/**
 * Allowed job status transitions. Pure.
 */
final class JobStateMachine
{
    /**
     * @return list<JobStatus>
     */
    public function allowedFrom(JobStatus $from): array
    {
        return match ($from) {
            JobStatus::Pending => [JobStatus::Reserved, JobStatus::Skipped, JobStatus::Cancelled],
            JobStatus::Reserved => [JobStatus::Claimed, JobStatus::Pending, JobStatus::Cancelled, JobStatus::Unknown],
            JobStatus::Claimed => [JobStatus::Running, JobStatus::Failed, JobStatus::Cancelled, JobStatus::Unknown],
            JobStatus::Running => [
                JobStatus::Completed,
                JobStatus::Failed,
                JobStatus::HumanRequired,
                JobStatus::Cancelled,
                JobStatus::Unknown,
            ],
            // Unknown = uncertain social side-effect. Reclaim with new fence, or human/cancel.
            // Do not accept bare Completed/Failed without a valid active fence (enforced in orchestrator).
            JobStatus::Unknown => [
                JobStatus::Claimed,
                JobStatus::HumanRequired,
                JobStatus::Cancelled,
            ],
            JobStatus::HumanRequired => [JobStatus::Running, JobStatus::Cancelled, JobStatus::Failed],
            JobStatus::Completed, JobStatus::Failed, JobStatus::Cancelled, JobStatus::Skipped => [],
        };
    }

    public function canTransition(JobStatus $from, JobStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        foreach ($this->allowedFrom($from) as $allowed) {
            if ($allowed === $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lease expiry while reserved/claimed/running → unknown (not immediate failed).
     */
    public function onLeaseExpired(JobStatus $from): ?JobStatus
    {
        return match ($from) {
            JobStatus::Reserved => JobStatus::Pending,
            JobStatus::Claimed, JobStatus::Running => JobStatus::Unknown,
            default => null,
        };
    }
}
