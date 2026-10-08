<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Linking;

/**
 * Hard guards for Internal Link V2 candidates.
 * Semantic ranking must not be asked to replace these checks.
 */
final class InternalLinkV2Guard
{
    /**
     * @param  list<array{ref: string, url?: string, eligible?: bool, same_as_source?: bool, already_linked_from_source?: bool}>  $candidates
     * @return array{accepted: list<array<string, mixed>>, rejected: list<array{ref: string, reason: string}>}
     */
    public function filter(string $sourceRef, array $candidates): array
    {
        $accepted = [];
        $rejected = [];
        foreach ($candidates as $candidate) {
            $ref = trim((string) ($candidate['ref'] ?? ''));
            $reason = $this->reason($sourceRef, $ref, $candidate);
            if ($reason !== null) {
                $rejected[] = ['ref' => $ref, 'reason' => $reason];
                continue;
            }
            $accepted[] = $candidate;
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function reason(string $sourceRef, string $ref, array $candidate): ?string
    {
        if ($ref === '' || $ref === $sourceRef || ($candidate['same_as_source'] ?? false) === true) {
            return 'self_link';
        }
        if (($candidate['eligible'] ?? false) !== true) {
            return 'ineligible';
        }
        $url = trim((string) ($candidate['url'] ?? ''));
        if ($url === '' || ! preg_match('#^(https?://|/)#', $url)) {
            return 'invalid_url';
        }
        if (($candidate['already_linked_from_source'] ?? false) === true) {
            return 'duplicate_source_target';
        }

        return null;
    }
}
