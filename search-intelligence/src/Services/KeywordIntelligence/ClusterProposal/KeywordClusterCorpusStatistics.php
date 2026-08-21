<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

final class KeywordClusterCorpusStatistics
{
    /**
     * @param  array<string, float>  $tokenWeights
     */
    public function __construct(
        public readonly int $documentCount,
        public readonly array $tokenWeights,
    ) {}

    /**
     * @param  list<KeywordClusterTokenProfile>  $profiles
     */
    public static function fromProfiles(array $profiles): self
    {
        $documentCount = count($profiles);
        if ($documentCount === 0) {
            return new self(0, []);
        }

        $documentFrequency = [];
        foreach ($profiles as $profile) {
            foreach (array_unique($profile->tokens) as $token) {
                $documentFrequency[$token] = ($documentFrequency[$token] ?? 0) + 1;
            }
        }

        $tokenWeights = [];
        foreach ($documentFrequency as $token => $frequency) {
            $tokenWeights[(string) $token] = log(($documentCount + 1.0) / ($frequency + 1.0)) + 1.0;
        }

        return new self($documentCount, $tokenWeights);
    }

    public function weight(string $token): float
    {
        return $this->tokenWeights[(string) $token] ?? 1.0;
    }
}
