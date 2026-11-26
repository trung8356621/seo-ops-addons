<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

/**
 * Provisional ranking for models absent from the curated recommended catalog.
 * Weights are heuristic policy, not measured benchmarks.
 */
final class AiProvisionalModelScore
{
    public const SOURCE_CURATED = 'curated';

    public const SOURCE_ESTIMATED = 'estimated';

    /**
     * @param  array<string, array{value: float|null, source: string, note?: string}>  $dimensions
     * @param  list<string>  $missingMetadata
     */
    public function __construct(
        public readonly float $score,
        public readonly float $confidence,
        public readonly string $scoreSource,
        public readonly string $capability,
        public readonly string $reason,
        public readonly array $missingMetadata = [],
        public readonly array $dimensions = [],
    ) {}

    /**
     * @return array{
     *   score: float,
     *   confidence: float,
     *   score_source: string,
     *   capability: string,
     *   reason: string,
     *   missing_metadata: list<string>,
     *   dimensions: array<string, array{value: float|null, source: string, note?: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'score' => round($this->score, 4),
            'confidence' => round($this->confidence, 4),
            'score_source' => $this->scoreSource,
            'capability' => $this->capability,
            'reason' => $this->reason,
            'missing_metadata' => array_values($this->missingMetadata),
            'dimensions' => $this->dimensions,
        ];
    }
}
