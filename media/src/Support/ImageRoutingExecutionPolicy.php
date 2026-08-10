<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

/**
 * Execution policy cho image_typography — sinh từ ImageRoutingStrategy.
 */
final class ImageRoutingExecutionPolicy
{
    /**
     * @param  list<string>  $models
     */
    public function __construct(
        public readonly array $models,
        public readonly int $candidateCount = 1,
        public readonly string $resolution = '2K',
        public readonly bool $validationRequired = false,
        public readonly float $minimumScore = 0.90,
        public readonly int $maxRenderAttempts = 1,
        public readonly bool $allowGeneralImageFallback = false,
        public readonly bool $typographyWarning = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'models' => $this->models,
            'candidate_count' => $this->candidateCount,
            'resolution' => $this->resolution,
            'validation_required' => $this->validationRequired,
            'minimum_score' => $this->minimumScore,
            'max_render_attempts' => $this->maxRenderAttempts,
            'allow_general_image_fallback' => $this->allowGeneralImageFallback,
            'typography_warning' => $this->typographyWarning,
        ];
    }
}
