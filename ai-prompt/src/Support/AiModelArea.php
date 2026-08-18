<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiModelArea: string
{
    case TextFast = 'fast_text';
    case TextLongform = 'long_form_text';
    case TextReasoning = 'reasoning_text';
    case Image = 'image';
    case Video = 'video';
    /** @deprecated Legacy Models tab. Migrated to a text primary type on sync/classify. */
    case Text = 'text';

    public const PRIMARY_TYPE_KEY = 'omi_primary_type';

    public const PRIMARY_TYPE_SOURCE_KEY = 'omi_primary_type_source';

    public const SOURCE_AUTO = 'auto';

    public const SOURCE_MANUAL = 'manual';

    /**
     * @return list<self>
     */
    public static function uiCases(): array
    {
        return [
            self::TextFast,
            self::TextLongform,
            self::TextReasoning,
            self::Image,
            self::Video,
        ];
    }

    /**
     * @return list<self>
     */
    public static function textPrimaryCases(): array
    {
        return [self::TextFast, self::TextLongform, self::TextReasoning];
    }

    public function isTextPrimary(): bool
    {
        return in_array($this, self::textPrimaryCases(), true);
    }

    public function routingGroup(): string
    {
        return match ($this) {
            self::Image => 'image',
            self::Video => 'video',
            default => 'text',
        };
    }

    public function requiredCapabilityKeys(): array
    {
        return match ($this) {
            self::TextFast, self::TextLongform, self::Text => [
                AiModelCapability::TextGenerate->value,
                AiModelCapability::TextReasoning->value,
            ],
            self::TextReasoning => [
                AiModelCapability::TextReasoning->value,
                AiModelCapability::TextGenerate->value,
            ],
            self::Image => [AiModelCapability::ImageGenerate->value],
            self::Video => [AiModelCapability::VideoGenerate->value],
        };
    }

    public static function fromProfile(AiExecutionProfile $profile): self
    {
        return match ($profile) {
            AiExecutionProfile::TextFast => self::TextFast,
            AiExecutionProfile::TextLongform => self::TextLongform,
            AiExecutionProfile::TextReasoning => self::TextReasoning,
            AiExecutionProfile::ImageGeneral, AiExecutionProfile::ImageTypography, AiExecutionProfile::ImageProduct => self::Image,
            AiExecutionProfile::VideoGeneral => self::Video,
        };
    }

    public static function tryFromMixed(mixed $value): self
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '' || $raw === 'text') {
            return self::TextFast;
        }

        return match ($raw) {
            'text.fast', 'fast', 'fast_text' => self::TextFast,
            'text.longform', 'longform', 'long_form', 'long_form_text' => self::TextLongform,
            'text.reasoning', 'reasoning', 'reasoning_text' => self::TextReasoning,
            default => self::tryFrom($raw) ?? self::TextFast,
        };
    }
}
