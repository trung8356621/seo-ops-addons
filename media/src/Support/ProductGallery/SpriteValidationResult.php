<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

/**
 * Image-only sprite validation outcome (no AI).
 * Alias contract name: ProductSpriteValidationResult.
 *
 * @phpstan-type PanelRect array{x: int, y: int, w: int, h: int, row: int, col: int}
 */
class SpriteValidationResult
{
    /** @deprecated use ProductGallerySource::AiChildren */
    public const SOURCE_AI_CHILDREN = 'ai_children';

    /** @deprecated use ProductGallerySource::OriginalImages — legacy alias original_fallback */
    public const SOURCE_ORIGINAL_FALLBACK = 'original_fallback';

    public const SOURCE_ORIGINAL_IMAGES = 'original_images';

    public const SOURCE_PENDING = 'pending';

    public const STRATEGY_FIXED_GRID = 'fixed_grid';

    public const STRATEGY_DETECTED_GUTTERS = 'detected_gutters';

    public const STRATEGY_NONE = 'none';

    /**
     * @param  list<PanelRect>  $rectangles
     * @param  list<string>  $hardFailures
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $softFlags
     * @param  array<string, float>  $softScores
     */
    public function __construct(
        public readonly bool $valid,
        public readonly bool $hardFailed,
        public readonly float $confidence,
        public readonly float $threshold,
        public readonly string $reason,
        public readonly int $expectedGrid,
        public readonly int $detectedPanels,
        public readonly array $rectangles = [],
        public readonly array $hardFailures = [],
        public readonly array $reasonCodes = [],
        public readonly array $softFlags = [],
        public readonly array $softScores = [],
        public readonly ?int $canvasWidth = null,
        public readonly ?int $canvasHeight = null,
        public readonly string $splitStrategy = self::STRATEGY_NONE,
    ) {}

    public function passesThreshold(?float $threshold = null): bool
    {
        $limit = $threshold ?? $this->threshold;

        return ! $this->hardFailed && $this->valid && $this->confidence >= $limit;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'hard_failed' => $this->hardFailed,
            'confidence' => round($this->confidence, 4),
            'threshold' => round($this->threshold, 4),
            'reason' => $this->reason,
            'reason_codes' => array_values($this->reasonCodes !== [] ? $this->reasonCodes : $this->hardFailures),
            'expected_grid' => $this->expectedGrid,
            'detected_panels' => $this->detectedPanels,
            'detected_panel_count' => $this->detectedPanels,
            'rectangles' => $this->rectangles,
            'hard_failures' => $this->hardFailures,
            'soft_flags' => $this->softFlags,
            'soft_scores' => $this->softScores,
            'canvas_width' => $this->canvasWidth,
            'canvas_height' => $this->canvasHeight,
            'split_strategy' => $this->splitStrategy,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rectangles = [];
        foreach (is_array($data['rectangles'] ?? null) ? $data['rectangles'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rectangles[] = [
                'x' => (int) ($row['x'] ?? 0),
                'y' => (int) ($row['y'] ?? 0),
                'w' => (int) ($row['w'] ?? 0),
                'h' => (int) ($row['h'] ?? 0),
                'row' => (int) ($row['row'] ?? 0),
                'col' => (int) ($row['col'] ?? 0),
            ];
        }

        $softScores = [];
        foreach (is_array($data['soft_scores'] ?? null) ? $data['soft_scores'] : [] as $key => $value) {
            $softScores[(string) $key] = (float) $value;
        }

        $hardFailures = array_values(array_map(
            static fn (mixed $v): string => (string) $v,
            is_array($data['hard_failures'] ?? null) ? $data['hard_failures'] : [],
        ));
        $reasonCodes = array_values(array_map(
            static fn (mixed $v): string => (string) $v,
            is_array($data['reason_codes'] ?? null) ? $data['reason_codes'] : $hardFailures,
        ));

        $confidence = (float) ($data['confidence'] ?? 0.0);
        $threshold = (float) ($data['threshold'] ?? 0.8);
        $hardFailed = (bool) ($data['hard_failed'] ?? ($hardFailures !== []));
        $valid = (bool) ($data['valid'] ?? (! $hardFailed && $confidence >= $threshold));

        return new self(
            valid: $valid,
            hardFailed: $hardFailed,
            confidence: $confidence,
            threshold: $threshold,
            reason: (string) ($data['reason'] ?? ''),
            expectedGrid: (int) ($data['expected_grid'] ?? 0),
            detectedPanels: (int) ($data['detected_panel_count'] ?? $data['detected_panels'] ?? 0),
            rectangles: $rectangles,
            hardFailures: $hardFailures,
            reasonCodes: $reasonCodes,
            softFlags: array_values(array_map(
                static fn (mixed $v): string => (string) $v,
                is_array($data['soft_flags'] ?? null) ? $data['soft_flags'] : [],
            )),
            softScores: $softScores,
            canvasWidth: isset($data['canvas_width']) ? (int) $data['canvas_width'] : null,
            canvasHeight: isset($data['canvas_height']) ? (int) $data['canvas_height'] : null,
            splitStrategy: (string) ($data['split_strategy'] ?? self::STRATEGY_NONE),
        );
    }

    /**
     * @param  list<string>  $hardFailures
     */
    public static function hardFail(
        string $reason,
        int $expectedGrid,
        array $hardFailures,
        ?int $canvasWidth = null,
        ?int $canvasHeight = null,
        int $detectedPanels = 0,
        float $threshold = 0.8,
    ): self {
        return new self(
            valid: false,
            hardFailed: true,
            confidence: 0.0,
            threshold: $threshold,
            reason: $reason,
            expectedGrid: $expectedGrid,
            detectedPanels: $detectedPanels,
            hardFailures: $hardFailures,
            reasonCodes: $hardFailures,
            canvasWidth: $canvasWidth,
            canvasHeight: $canvasHeight,
            splitStrategy: self::STRATEGY_NONE,
        );
    }
}
