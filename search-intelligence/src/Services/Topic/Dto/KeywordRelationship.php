<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto;

/**
 * One-keyword relationship graph (Keyword MCP type-2) — schema keyword.relationship.v1.
 *
 * On-demand read model only. Never written to seo_mcp_source_snapshots.
 */
final class KeywordRelationship
{
    public const SCHEMA = 'keyword.relationship.v1';

    public const RELATED_LIMIT = 50;

    public const LINK_LIMIT = 50;

    public const GSC_LIMIT = 50;

    public const PLANNING_LIMIT = 20;

    public const DNA_LIMIT = 30;

    /**
     * @param  array<string, mixed>  $keyword
     * @param  list<array<string, mixed>>  $topics
     * @param  list<array<string, mixed>>  $focusArticles
     * @param  array{topic_dna: list<array{phrase: string, weight: int}>}  $dna
     * @param  array{available: bool, inbound: array<string, mixed>, outbound: array<string, mixed>}  $internalLinks
     * @param  array{available: bool, query_mappings: array<string, mixed>}  $gsc
     * @param  array{available: bool, items: array<string, mixed>}  $planning
     * @param  list<string>  $relationIssues
     * @param  list<string>  $availableSections
     */
    public function __construct(
        public readonly int $siteId,
        public readonly array $keyword,
        public readonly array $topics,
        public readonly array $focusArticles,
        public readonly array $dna,
        public readonly RelationshipListSlice $relatedKeywords,
        public readonly array $internalLinks,
        public readonly array $gsc,
        public readonly array $planning,
        public readonly array $relationIssues,
        public readonly array $availableSections,
        public readonly string $generatedAt,
        public readonly ?string $sourceUpdatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'site_id' => $this->siteId,
            'keyword' => $this->keyword,
            'topics' => $this->topics,
            'focus_articles' => $this->focusArticles,
            'dna' => $this->dna,
            'related_keywords' => $this->relatedKeywords->toArray(),
            'internal_links' => $this->internalLinks,
            'gsc' => $this->gsc,
            'planning' => $this->planning,
            'meta' => [
                'generated_at' => $this->generatedAt,
                'source_updated_at' => $this->sourceUpdatedAt,
                'available_sections' => $this->availableSections,
                'relation_issues' => $this->relationIssues,
                'limits' => [
                    'related_keywords' => self::RELATED_LIMIT,
                    'internal_links' => self::LINK_LIMIT,
                    'gsc_query_mappings' => self::GSC_LIMIT,
                    'planning_items' => self::PLANNING_LIMIT,
                ],
            ],
        ];
    }
}
