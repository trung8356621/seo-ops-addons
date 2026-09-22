<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto;

/**
 * Site-scoped Keyword Landscape snapshot (SSOT for Keyword MCP type-1 consumers).
 */
final class KeywordLandscape
{
    /**
     * @param  list<KeywordLandscapeTopic>  $topics  Deterministic order: mcp ASC, name ASC, id ASC
     */
    public function __construct(
        public readonly int $siteId,
        public readonly array $topics,
        public readonly ?string $sourceUpdatedAt,
    ) {}

    public function topicCount(): int
    {
        return count($this->topics);
    }

    public function findById(int $topicId): ?KeywordLandscapeTopic
    {
        foreach ($this->topics as $topic) {
            if ($topic->id === $topicId) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * @return array{
     *   site_id: int,
     *   topic_count: int,
     *   source_updated_at: string|null,
     *   topics: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'site_id' => $this->siteId,
            'topic_count' => $this->topicCount(),
            'source_updated_at' => $this->sourceUpdatedAt,
            'topics' => array_map(
                static fn (KeywordLandscapeTopic $topic): array => $topic->toArray(),
                $this->topics,
            ),
        ];
    }

    /**
     * Deterministic payload fragment for MCP snapshot hashing.
     *
     * @return array{metrics: array{topic_count: int}, summary: array<string, mixed>, context: array{topics: list<array<string, mixed>>}}
     */
    public function toMcpPayloadParts(): array
    {
        $topics = array_map(
            static fn (KeywordLandscapeTopic $topic): array => $topic->toMcpContextRow(),
            $this->topics,
        );

        return [
            'metrics' => [
                'topic_count' => $this->topicCount(),
            ],
            'summary' => [],
            'context' => [
                'topics' => $topics,
            ],
        ];
    }
}
