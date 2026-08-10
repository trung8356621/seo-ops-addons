<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

/**
 * Capability model (Phase 1 tối thiểu cho image routing).
 * Product gallery là runtime context, không phải capability.
 */
enum ImageCapability: string
{
    case TextGeneration = 'text_generation';
    case ImageInput = 'image_input';
    case ImageGeneration = 'image_generation';
    case GeneralImage = 'general_image';
    case TypographySupported = 'typography_supported';
    case TypographyRecommended = 'typography_recommended';
    case VideoGeneration = 'video_generation';
    case Unknown = 'unknown';

    /**
     * Nhóm hiển thị Model Status.
     *
     * @return list<string>
     */
    public static function displayGroups(): array
    {
        return [
            'text',
            'image',
            'image_typography',
            'video',
            'unknown',
        ];
    }

    public static function displayGroupForCapabilities(array $capabilities): string
    {
        $set = array_fill_keys(array_map('strval', $capabilities), true);

        if (isset($set[self::Unknown->value]) && count($set) === 1) {
            return 'unknown';
        }

        // Pro-class typography models → nhóm Image Typography; Flash image → Image.
        if (isset($set[self::TypographyRecommended->value])) {
            return 'image_typography';
        }

        if (isset($set[self::ImageGeneration->value]) || isset($set[self::GeneralImage->value])) {
            return 'image';
        }

        if (isset($set[self::VideoGeneration->value])) {
            return 'video';
        }

        if (isset($set[self::TextGeneration->value])) {
            return 'text';
        }

        return 'unknown';
    }
}
