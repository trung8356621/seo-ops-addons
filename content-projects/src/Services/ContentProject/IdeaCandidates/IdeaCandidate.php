<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates;

/**
 * Projection of TYPE_SUGGEST staging row — not a persisted Idea entity.
 *
 * @phpstan-type CandidateArray array{
 *   candidate_ref: string,
 *   keyword_id: int,
 *   phrase: string,
 *   source: string,
 *   source_label: string,
 *   source_article_id: int|null,
 *   source_article_title: string|null,
 *   vocabulary_group: string|null,
 *   vocabulary_group_label: string|null,
 *   hint: string|null
 * }
 */
final class IdeaCandidate
{
    public function __construct(
        public readonly string $candidateRef,
        public readonly int $keywordId,
        public readonly string $phrase,
        public readonly string $source,
        public readonly string $sourceLabel,
        public readonly ?int $sourceArticleId = null,
        public readonly ?string $sourceArticleTitle = null,
        public readonly ?string $vocabularyGroup = null,
        public readonly ?string $vocabularyGroupLabel = null,
        public readonly ?string $hint = null,
    ) {}

    /**
     * @return CandidateArray
     */
    public function toArray(): array
    {
        return [
            'candidate_ref' => $this->candidateRef,
            'keyword_id' => $this->keywordId,
            'phrase' => $this->phrase,
            'source' => $this->source,
            'source_label' => $this->sourceLabel,
            'source_article_id' => $this->sourceArticleId,
            'source_article_title' => $this->sourceArticleTitle,
            'vocabulary_group' => $this->vocabularyGroup,
            'vocabulary_group_label' => $this->vocabularyGroupLabel,
            'hint' => $this->hint,
        ];
    }

    public static function ref(string $sourceKey, int $keywordId): string
    {
        return $sourceKey.':'.$keywordId;
    }
}
