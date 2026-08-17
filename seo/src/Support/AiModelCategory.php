<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Nhãn đại diện (Unified Category) — người dùng chọn; hệ thống map sang raw model trong seo_ai_models.
 */
final class AiModelCategory
{
    public const GEMINI_PRO = 'gemini_pro';

    public const GEMINI_FLASH = 'gemini_flash';

    public const IMAGEN_PRO = 'imagen_pro';

    public const CLAUDE_OPUS = 'claude_opus';

    public const CLAUDE_SONNET = 'claude_sonnet';

    public const CLAUDE_HAIKU = 'claude_haiku';

    public const DEEPSEEK_CHAT = 'deepseek_chat';

    public const DEEPSEEK_REASONER = 'deepseek_reasoner';

    /**
     * @return array<string, string>
     */
    public static function promptSelectOptions(): array
    {
        return [
            self::GEMINI_PRO => 'GEMINI Pro (Dàn ý, phân tích sâu)',
            self::GEMINI_FLASH => 'GEMINI Flash (Tốc độ & chi phí)',
            self::IMAGEN_PRO => 'GEMINI Image Pro (Ảnh chất lượng cao)',
            self::CLAUDE_OPUS => 'CLAUDE Opus (Chất lượng đỉnh cao)',
            self::CLAUDE_SONNET => 'CLAUDE Sonnet (Viết bài chuẩn SEO)',
            self::CLAUDE_HAIKU => 'CLAUDE Haiku (Nhanh & tiết kiệm)',
            self::DEEPSEEK_CHAT => 'DeepSeek Chat (Long-form / fast text)',
            self::DEEPSEEK_REASONER => 'DeepSeek Reasoner (Planning / analysis)',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function connectionSelectOptions(string $provider): array
    {
        return match ($provider) {
            'gemini' => [
                self::GEMINI_FLASH => 'GEMINI Flash (Tốc độ & chi phí)',
                self::GEMINI_PRO => 'GEMINI Pro (Dàn ý, phân tích sâu)',
                self::IMAGEN_PRO => 'GEMINI Image Pro (Ảnh chất lượng cao)',
            ],
            'claude' => [
                self::CLAUDE_SONNET => 'CLAUDE Sonnet (Viết bài chuẩn SEO)',
                self::CLAUDE_OPUS => 'CLAUDE Opus (Chất lượng đỉnh cao)',
                self::CLAUDE_HAIKU => 'CLAUDE Haiku (Nhanh & tiết kiệm)',
            ],
            'deepseek' => [
                self::DEEPSEEK_CHAT => 'DeepSeek Chat (Long-form / fast text)',
                self::DEEPSEEK_REASONER => 'DeepSeek Reasoner (Planning / analysis)',
            ],
            default => [],
        };
    }

    public static function isValid(string $category): bool
    {
        return array_key_exists($category, self::promptSelectOptions());
    }

    public static function defaultForProvider(string $provider): string
    {
        return match ($provider) {
            'claude' => self::CLAUDE_SONNET,
            'deepseek' => self::DEEPSEEK_CHAT,
            default => self::GEMINI_FLASH,
        };
    }

    /**
     * @deprecated Image rendering không dùng category này — dùng ImageRoutingStrategy.
     * Text path: bỏ qua prompts.model_category (Representative Model đã gỡ khỏi form);
     * caller nên map từ RenderingPreference thay vì field prompt.
     */
    public static function resolveForPrompt(?string $modelCategory, string $provider, string $toolType = 'default'): string
    {
        if (ImageToolType::fromMixed($toolType)->isImagePipeline()) {
            return self::IMAGEN_PRO;
        }

        // Phase 1: không còn tin prompts.model_category cho routing.
        unset($modelCategory);

        return self::defaultForProvider($provider);
    }

    /**
     * Category hợp lệ với nhà cung cấp.
     */
    public static function matchesProvider(string $category, string $provider): bool
    {
        return match ($provider) {
            'gemini' => in_array($category, [self::GEMINI_PRO, self::GEMINI_FLASH, self::IMAGEN_PRO], true),
            'claude' => in_array($category, [self::CLAUDE_OPUS, self::CLAUDE_SONNET, self::CLAUDE_HAIKU], true),
            'deepseek' => in_array($category, [self::DEEPSEEK_CHAT, self::DEEPSEEK_REASONER], true),
            default => false,
        };
    }
}
