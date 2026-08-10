<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;

/**
 * Output of TopicalMapBuilder::buildFromRequest().
 */
final class TopicalMapBuildResult
{
    /**
     * @param  list<array<string, mixed>>  $rootTopics
     * @param  list<array<string, mixed>>  $topics
     * @param  list<array<string, mixed>>  $assignments
     * @param  list<array<string, mixed>>  $relationships
     * @param  array<string, mixed>  $coverageSummary
     * @param  list<array<string, mixed>>  $linkSuggestions
     * @param  list<array<string, mixed>>  $conflicts
     * @param  list<string>  $warnings
     * @param  float|array<string, mixed>  $confidence
     */
    public function __construct(
        public readonly array $rootTopics = [],
        public readonly array $topics = [],
        public readonly array $assignments = [],
        public readonly array $relationships = [],
        public readonly array $coverageSummary = [],
        public readonly array $linkSuggestions = [],
        public readonly array $conflicts = [],
        public readonly array $warnings = [],
        public readonly float|array $confidence = 0.0,
        public readonly ?string $mapVersionRef = null,
        public readonly string $resultCode = KeywordIntelligenceActionCodes::TOPICAL_MAP_BUILD_COMPLETED,
        public readonly bool $persisted = true,
    ) {}

    public function isSuccessful(): bool
    {
        return in_array($this->resultCode, [
            KeywordIntelligenceActionCodes::TOPICAL_MAP_BUILD_COMPLETED,
            KeywordIntelligenceActionCodes::TOPICAL_MAP_BUILD_PARTIAL,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'result_code' => $this->resultCode,
            'root_topics' => $this->rootTopics,
            'topics' => $this->topics,
            'assignments' => $this->assignments,
            'relationships' => $this->relationships,
            'coverage_summary' => $this->coverageSummary,
            'link_suggestions' => $this->linkSuggestions,
            'conflicts' => $this->conflicts,
            'warnings' => $this->warnings,
            'confidence' => $this->confidence,
            'map_version_ref' => $this->mapVersionRef,
            'persisted' => $this->persisted,
        ];
    }
}
