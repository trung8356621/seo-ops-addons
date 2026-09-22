<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscape;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;

/**
 * Deterministic novelty filter: reject candidates that match existing landscape Topic names.
 */
final class DiscoverNewTopicsDuplicateFilter
{
    public function __construct(
        private readonly KeywordLandscapeGateway $landscape,
    ) {}

    /**
     * @param  list<array{candidate_key: string, name: string, target_dna_count: int, dna: list<string>}>  $candidates
     * @return array{
     *   accepted: list<array{candidate_key: string, name: string, target_dna_count: int, dna: list<string>}>,
     *   rejected: list<array{reason: string, candidate: array<string, mixed>}>
     * }
     */
    public function filter(int $siteId, array $candidates): array
    {
        $existingKeys = $this->existingNameKeys($this->landscape->forSite($siteId, false));
        $accepted = [];
        $rejected = [];

        foreach ($candidates as $candidate) {
            $nameKey = AuditNoteDnaNormalizer::normalizeKey((string) ($candidate['name'] ?? ''));
            if ($nameKey === '' || isset($existingKeys[$nameKey])) {
                $rejected[] = [
                    'reason' => 'duplicate_existing_topic',
                    'candidate' => $candidate,
                ];

                continue;
            }
            $accepted[] = $candidate;
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * @return array<string, true>
     */
    private function existingNameKeys(KeywordLandscape $landscape): array
    {
        $keys = [];
        foreach ($landscape->topics as $topic) {
            $key = AuditNoteDnaNormalizer::normalizeKey($topic->name);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }
}
