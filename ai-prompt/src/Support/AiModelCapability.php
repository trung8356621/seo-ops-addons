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
    /** Bounded choice among a closed option set. Not prose generation. */
    case DecisionChoice = 'decision.choice';
    /** Bounded numeric score in a caller-supplied range. */
    case DecisionScore = 'decision.score';
    /** Bounded probability from 0 to 1. */
    case DecisionProbability = 'decision.probability';

    /**
     * @return list<self>
     */
    public static function multimedia(): array
    {
        return [self::ImageGenerate, self::ImageTypography, self::VideoGenerate];
    }

    /**
     * Decision / routing model class. Separate from text generation.
     *
     * @return list<self>
     */
    public static function decision(): array
    {
        return [self::DecisionChoice, self::DecisionScore, self::DecisionProbability];
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
            self::DecisionChoice => 'Choice',
            self::DecisionScore => 'Score',
            self::DecisionProbability => 'Probability',
        };
    }

    public function isMultimedia(): bool
    {
        return in_array($this, self::multimedia(), true);
    }

    public function isDecision(): bool
    {
        return in_array($this, self::decision(), true);
    }
}
