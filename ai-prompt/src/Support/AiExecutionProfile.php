<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiExecutionProfile: string
{
    case TextFast = 'text.fast';
    case TextLongform = 'text.longform';
    case TextReasoning = 'text.reasoning';
    case ImageGeneral = 'image.general';
    case ImageTypography = 'image.typography';
    case ImageProduct = 'image.product';
    case VideoGeneral = 'video.general';

    public function group(): string
    {
        return match ($this) {
            self::TextFast, self::TextLongform, self::TextReasoning => 'text',
            self::ImageGeneral, self::ImageTypography, self::ImageProduct => 'image',
            self::VideoGeneral => 'video',
        };
    }

    public function displayName(): string
    {
        return match ($this) {
            self::TextFast => 'Fast Text',
            self::TextLongform => 'Long-form Text',
            self::TextReasoning => 'Reasoning Text',
            self::ImageGeneral => 'General Image',
            self::ImageTypography => 'Typography Image',
            self::ImageProduct => 'Product Image',
            self::VideoGeneral => 'General Video',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TextFast => 'Title, meta description, FAQ, short utilities',
            self::TextLongform => 'Article generation, rewrite, long translation',
            self::TextReasoning => 'Outline, keyword discovery, SEO analysis, planning',
            self::ImageGeneral => 'General image generation',
            self::ImageTypography => 'Images that must render text/typography',
            self::ImageProduct => 'Product gallery / product photos',
            self::VideoGeneral => 'Video generation',
        };
    }

    /**
     * @return list<AiModelCapability>
     */
    public function requiredCapabilities(): array
    {
        return match ($this) {
            self::TextFast, self::TextLongform => [AiModelCapability::TextGenerate],
            self::TextReasoning => [AiModelCapability::TextReasoning],
            self::ImageGeneral, self::ImageProduct => [AiModelCapability::ImageGenerate],
            self::ImageTypography => [AiModelCapability::ImageGenerate, AiModelCapability::ImageTypography],
            self::VideoGeneral => [AiModelCapability::VideoGenerate],
        };
    }

    /**
     * @return list<string>
     */
    public function requiredCapabilityKeys(): array
    {
        return array_map(
            static fn (AiModelCapability $capability): string => $capability->value,
            $this->requiredCapabilities(),
        );
    }

    public function isMedia(): bool
    {
        return $this->group() !== 'text';
    }

    /**
     * @return list<self>
     */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $profile): bool => $profile->group() === $group,
        ));
    }
}
