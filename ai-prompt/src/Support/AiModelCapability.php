<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Model-level capability keys. Provider is never a capability.
 */
enum AiModelCapability: string
{
    case TextGenerate = 'text.generate';
    case TextReasoning = 'text.reasoning';
    case StructuredOutput = 'structured_output';
    case ToolCall = 'tool_call';
    case VisionInput = 'vision.input';
    case ImageGenerate = 'image.generate';
    case ImageTypography = 'image.typography';
    case VideoGenerate = 'video.generate';

    /**
     * @return list<self>
     */
    public static function multimedia(): array
    {
        return [self::ImageGenerate, self::ImageTypography, self::VideoGenerate];
    }

    public function badgeLabel(): string
    {
        return match ($this) {
            self::TextGenerate => 'Text',
            self::TextReasoning => 'Reasoning',
            self::StructuredOutput => 'JSON',
            self::ToolCall => 'Tools',
            self::VisionInput => 'Vision',
            self::ImageGenerate => 'Image',
            self::ImageTypography => 'Typography',
            self::VideoGenerate => 'Video',
        };
    }

    public function isMultimedia(): bool
    {
        return in_array($this, self::multimedia(), true);
    }
}
