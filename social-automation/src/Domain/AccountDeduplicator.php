<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Prefer unique strong identities; never invent uniqueness from declared labels.
 *
 * @phpstan-type Candidate array{
 *     session_id: string|int,
 *     account?: array<string, mixed>|null
 * }
 */
final class AccountDeduplicator
{
    /**
     * @param  list<Candidate>  $candidates
     * @return list<Candidate>
     */
    public function uniqueByStrongIdentity(array $candidates): array
    {
        $seen = [];
        $out = [];

        foreach ($candidates as $candidate) {
            $account = is_array($candidate['account'] ?? null) ? $candidate['account'] : [];
            $key = AccountIdentityKey::strongKey($account);
            if ($key === null) {
                // Incomplete identity — keep (cannot prove duplicate).
                $out[] = $candidate;
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $candidate;
        }

        return $out;
    }
}
