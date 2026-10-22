<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Claim fencing — stale Runner attempts must not mutate a newer claim / Unknown job.
 */
final class AttemptFencePolicy
{
    public const ERR_MISSING = 'attempt_uuid_required';

    public const ERR_STALE = 'stale_attempt';

    public const ERR_MISMATCH = 'attempt_fence_mismatch';

    /**
     * @return array{ok: bool, error?: string}
     */
    public function validate(?string $presentedAttemptUuid, ?string $activeAttemptUuid, bool $requirePresented = true): array
    {
        $presented = trim((string) $presentedAttemptUuid);
        $active = trim((string) $activeAttemptUuid);

        if ($requirePresented && $presented === '') {
            return ['ok' => false, 'error' => self::ERR_MISSING];
        }

        if ($active === '') {
            // Lease expiry clears the fence → runner must not complete; needs human/cancel or reclaim.
            return ['ok' => false, 'error' => self::ERR_STALE];
        }

        if ($presented === '') {
            return ['ok' => false, 'error' => self::ERR_MISSING];
        }

        if (! hash_equals($active, $presented)) {
            return ['ok' => false, 'error' => self::ERR_MISMATCH];
        }

        return ['ok' => true];
    }

    /**
     * Idempotent duplicate result for the same fence after terminal success.
     */
    public function isDuplicateTerminal(?string $presented, ?string $active, string $jobStatus): bool
    {
        $presented = trim((string) $presented);
        $active = trim((string) $active);
        if ($presented === '' || $active === '' || ! hash_equals($active, $presented)) {
            return false;
        }

        return in_array($jobStatus, ['completed', 'failed', 'human_required', 'cancelled'], true);
    }
}
