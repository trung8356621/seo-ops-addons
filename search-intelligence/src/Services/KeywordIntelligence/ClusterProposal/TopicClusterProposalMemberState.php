<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

final readonly class TopicClusterProposalMemberState
{
    /**
     * @param  list<string>  $groupKeys
     */
    public function __construct(
        public int $keywordId,
        public string $classificationHash,
        public string $inputHash,
        public string $phraseKind,
        public string $seoIntent,
        public bool $isSeoKeyword,
        public bool $isAmbiguous,
        public string $clusterKey,
        public array $groupKeys,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fingerprintPayload(): array
    {
        $groups = $this->groupKeys;
        sort($groups, SORT_STRING);

        return [
            'keyword_id' => $this->keywordId,
            'classification_hash' => $this->classificationHash,
            'input_hash' => $this->inputHash,
            'phrase_kind' => $this->phraseKind,
            'seo_intent' => $this->seoIntent,
            'is_seo_keyword' => $this->isSeoKeyword,
            'is_ambiguous' => $this->isAmbiguous,
            'group_keys' => $groups,
        ];
    }

    public function stateHash(): string
    {
        return hash('sha256', json_encode($this->fingerprintPayload(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
