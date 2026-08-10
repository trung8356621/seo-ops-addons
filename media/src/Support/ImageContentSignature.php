<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

/**
 * Chữ ký nội dung ảnh (sample grid) — so sánh source vs output sau convert.
 */
final class ImageContentSignature
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $bytes,
        public readonly int $samples,
        public readonly int $visibleSamples,
        public readonly float $visibleRatio,
        public readonly float $alphaMin,
        public readonly float $alphaMax,
        public readonly float $alphaMean,
        public readonly float $lumaMean,
        public readonly float $lumaStd,
        public readonly float $rMean,
        public readonly float $gMean,
        public readonly float $bMean,
        public readonly float $channelStdMax,
        public readonly int $distinctBuckets,
        public readonly bool $fullyTransparent,
        public readonly bool $nearUniform,
    ) {}

    public function hasVisibleContent(): bool
    {
        return ! $this->fullyTransparent && $this->visibleSamples > 0;
    }

    public function hasSignificantVariance(): bool
    {
        return $this->lumaStd > 0.04
            || $this->channelStdMax > 0.04
            || $this->distinctBuckets >= 4;
    }

    /**
     * @return array<string, int|float|bool>
     */
    public function summary(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'bytes' => $this->bytes,
            'samples' => $this->samples,
            'visible_ratio' => round($this->visibleRatio, 4),
            'alpha_mean' => round($this->alphaMean, 4),
            'luma_mean' => round($this->lumaMean, 4),
            'luma_std' => round($this->lumaStd, 4),
            'channel_std_max' => round($this->channelStdMax, 4),
            'distinct_buckets' => $this->distinctBuckets,
            'fully_transparent' => $this->fullyTransparent,
            'near_uniform' => $this->nearUniform,
        ];
    }
}
