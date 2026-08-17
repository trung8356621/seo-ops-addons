<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Built-in model → capability map. Never infers image/video from provider alone.
 */
final class BuiltInModelCapabilityCatalog
{
    /**
     * @return array<string, list<string>>
     */
    public static function deepseek(): array
    {
        return [
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
     * @return list<string>|null
     */
    public static function forProviderModel(string $provider, string $model): ?array
    {
        $provider = strtolower(trim($provider));
        $model = self::normalizeModel($model);
        if ($provider === '' || $model === '') {
            return null;
        }

        $map = match ($provider) {
            ApiConnectionProviders::DEEPSEEK => self::deepseek(),
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
