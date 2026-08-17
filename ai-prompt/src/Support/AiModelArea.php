<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiModelArea: string
{
    case Text = 'text';
    case Image = 'image';
    case Video = 'video';

    public function requiredCapabilityKeys(): array
    {
        return match ($this) {
            self::Text => [
                AiModelCapability::TextGenerate->value,
                AiModelCapability::TextReasoning->value,
            ],
            self::Image => [AiModelCapability::ImageGenerate->value],
            self::Video => [AiModelCapability::VideoGenerate->value],
        };
    }

    public static function fromProfile(AiExecutionProfile $profile): self
    {
        return match ($profile->group()) {
            'image' => self::Image,
            'video' => self::Video,
            default => self::Text,
        };
    }

    public static function tryFromMixed(mixed $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Text;
    }
}
