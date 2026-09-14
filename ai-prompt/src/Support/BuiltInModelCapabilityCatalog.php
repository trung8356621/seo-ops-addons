<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Built-in model → capability map. Never infers image/video from provider alone.
 *
 * DeepSeek: exact legacy aliases remain for historical rows; current provider models
 * (e.g. deepseek-flash) resolve via {@see deepseekCapabilitiesFor()} thinking-mode policy.
 */
final class BuiltInModelCapabilityCatalog
{
    /**
     * Legacy exact IDs only — not a live seed catalog.
     *
     * @return array<string, list<string>>
     */
    public static function deepseek(): array
    {
        return [
            // Legacy aliases (historical / inactive after provider sync).
            'deepseek-chat' => [
                AiModelCapability::TextGenerate->value,
                AiModelCapability::StructuredOutput->value,
                AiModelCapability::ToolCall->value,
            ],
            'deepseek-reasoner' => [
                AiModelCapability::TextGenerate->value,
                AiModelCapability::TextReasoning->value,
                AiModelCapability::StructuredOutput->value,
            ],
        ];
    }

    /**
     * Resolve DeepSeek Direct capabilities for a provider model id.
     *
     * @return list<string>|null
     */
    public static function deepseekCapabilitiesFor(string $model): ?array
    {
        $model = self::normalizeModel($model);
        if ($model === '') {
            return null;
        }

        $exact = self::deepseek()[$model] ?? null;
        if ($exact !== null) {
            return $exact;
        }

        $lower = strtolower($model);
        if (! str_starts_with($lower, 'deepseek')) {
            return null;
        }
        if (str_contains($lower, 'image') || str_contains($lower, 'video') || str_contains($lower, 'vision')) {
            return [];
        }

        // Current DeepSeek V4 text models expose reasoning via thinking mode.
        // Executor enables thinking when the routed profile/hook requires it.
        return [
            AiModelCapability::TextGenerate->value,
            AiModelCapability::TextReasoning->value,
            AiModelCapability::StructuredOutput->value,
            AiModelCapability::ToolCall->value,
        ];
    }

    /**
     * @return list<string>|null
     */
    public static function forProviderModel(string $provider, string $model): ?array
    {
        $provider = strtolower(trim($provider));
        $model = self::normalizeModel($model);
        if ($provider === '' || $model === '') {
            return null;
        }

        if ($provider === ApiConnectionProviders::DEEPSEEK) {
            return self::deepseekCapabilitiesFor($model);
        }

        $map = match ($provider) {
            ApiConnectionProviders::CLAUDE => self::claude(),
            ApiConnectionProviders::GEMINI => self::gemini(),
            default => [],
        };

        return $map[$model] ?? null;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function claude(): array
    {
        $text = [
            AiModelCapability::TextGenerate->value,
            AiModelCapability::TextReasoning->value,
            AiModelCapability::StructuredOutput->value,
            AiModelCapability::ToolCall->value,
        ];

        return [
            'claude-opus-4-20250514' => $text,
            'claude-sonnet-4-20250514' => $text,
            'claude-3-5-sonnet-20240620' => $text,
            'claude-3-5-haiku-20241022' => [
                AiModelCapability::TextGenerate->value,
                AiModelCapability::StructuredOutput->value,
            ],
            'claude-3-haiku-20240307' => [
                AiModelCapability::TextGenerate->value,
            ],
        ];
    }

    /**
     * Gemini capabilities come from GoogleAiModelRegistry via ModelCapabilityRegistry.
     * This map only covers explicit extras (reasoning) for known text models.
     *
     * @return array<string, list<string>>
     */
    private static function gemini(): array
    {
        return [];
    }

    public static function normalizeModel(string $model): string
    {
        $model = trim($model);
        if (str_starts_with($model, 'models/')) {
            $model = substr($model, 7);
        }

        return $model;
    }
}
