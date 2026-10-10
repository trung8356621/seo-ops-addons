<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Integration;

use InvalidArgumentException;

final class RoutingReviewChoiceValidator
{
    /** @return array{selected_candidate_id: string|null, preferred_candidate_id: string|null, none_of_above: bool} */
    public function validate(?array $snapshot, mixed $preferredCandidateId, mixed $noneOfAbove): array
    {
        $allowedIds = is_array($snapshot)
            ? array_values(array_filter(array_map(
                static fn ($candidate): ?string => is_array($candidate) && is_string($candidate['id'] ?? null)
                    ? $candidate['id']
                    : null,
                (array) ($snapshot['candidates'] ?? []),
            )))
            : [];
        if ($allowedIds === []) {
            throw new InvalidArgumentException('This response has no routing review snapshot.');
        }
        if (! is_bool($noneOfAbove)) {
            throw new InvalidArgumentException('None-of-the-above must be boolean.');
        }
        if ($noneOfAbove === true && $preferredCandidateId !== null) {
            throw new InvalidArgumentException('Choose a candidate or none of the above, not both.');
        }
        if ($noneOfAbove === false
            && (! is_string($preferredCandidateId) || ! in_array($preferredCandidateId, $allowedIds, true))) {
            throw new InvalidArgumentException('Preferred candidate was not offered for this response.');
        }

        $selected = $snapshot['selected_candidate_id'] ?? null;

        return [
            'selected_candidate_id' => is_string($selected) && in_array($selected, $allowedIds, true) ? $selected : null,
            'preferred_candidate_id' => $noneOfAbove ? null : $preferredCandidateId,
            'none_of_above' => $noneOfAbove,
        ];
    }
}
