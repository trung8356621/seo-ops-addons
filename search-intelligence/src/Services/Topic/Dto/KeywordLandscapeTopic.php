<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto;

/**
 * One site-level Keyword Landscape topic row (Topic Core identity).
 *
 * article_count = DISTINCT Focus Articles of MCP-eligible member Keywords
 * (not linkMap rows, not linked source articles, not internal edges).
 *
 * @phpstan-type DnaRow array{phrase: string, weight: int}
 */
final class KeywordLandscapeTopic
{
    /**
     * @param  list<DnaRow>  $dna
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly float $mcp,
        public readonly int $dnaCount,
        public readonly int $articleCount,
        public readonly bool $hasFocusArticle,
        public readonly string $coverage,
        public readonly string $status,
        public readonly array $dna,
        public readonly ?string $updatedAt,
    ) {}

    /**
     * @return array{
     *   id: int,
     *   name: string,
     *   mcp: float,
     *   dna_count: int,
     *   article_count: int,
     *   has_focus_article: bool,
     *   coverage: string,
     *   status: string,
     *   dna: list<DnaRow>,
     *   updated_at: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mcp' => $this->mcp,
            'dna_count' => $this->dnaCount,
            'article_count' => $this->articleCount,
            'has_focus_article' => $this->hasFocusArticle,
            'coverage' => $this->coverage,
            'status' => $this->status,
            'dna' => $this->dna,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Compact MCP context row (no UI / planning fields).
     *
     * @return array{
     *   id: int,
     *   name: string,
     *   mcp: float,
     *   dna_count: int,
     *   article_count: int,
     *   coverage: string,
     *   dna: list<DnaRow>
     * }
     */
    public function toMcpContextRow(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mcp' => $this->mcp,
            'dna_count' => $this->dnaCount,
            'article_count' => $this->articleCount,
            'coverage' => $this->coverage,
            'dna' => $this->dna,
        ];
    }
}
