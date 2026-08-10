<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

/**
 * Prompt tool type — quyết định pipeline + capability filter (không chọn raw model).
 */
enum ImageToolType: string
{
    case Default = 'default';
    case Image = 'image';
    case ImageTypography = 'image_typography';
    case Video = 'video';

    public static function fromMixed(mixed $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            self::Image->value => self::Image,
            self::ImageTypography->value => self::ImageTypography,
            self::Video->value => self::Video,
            default => self::Default,
        };
    }

    public function isImagePipeline(): bool
    {
        return $this === self::Image || $this === self::ImageTypography;
    }

    public function isTypography(): bool
    {
        return $this === self::ImageTypography;
    }

    public function isMediaTool(): bool
    {
        return $this->isImagePipeline() || $this === self::Video;
    }

    /**
     * Capability bắt buộc khi filter model route.
     *
     * @return list<string>
     */
    public function requiredCapabilities(): array
    {
        return match ($this) {
            self::Image => [ImageCapability::GeneralImage->value],
            self::ImageTypography => [ImageCapability::TypographySupported->value],
            self::Video => [ImageCapability::VideoGeneration->value],
            self::Default => [ImageCapability::TextGeneration->value],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function promptSelectOptions(): array
    {
        return [
            self::Default->value => __('seo-content-ai::filament.prompt.tool_default'),
            self::Image->value => __('seo-content-ai::filament.prompt.tool_image'),
            self::ImageTypography->value => __('seo-content-ai::filament.prompt.tool_image_typography'),
            self::Video->value => __('seo-content-ai::filament.prompt.tool_video'),
        ];
    }
}
