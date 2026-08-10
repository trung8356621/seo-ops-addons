<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Model IDs khớp Google AI Studio (generateContent).
 *
 * @see https://ai.google.dev/gemini-api/docs/models
 */
final class GeminiModelCatalog
{
    /** @var array<string, string> */
    private const ALIASES = [
        'gemini-1.5-flash' => 'gemini-3-flash-preview',
        'gemini-1.5-flash-preview' => 'gemini-3-flash-preview',
        'gemini-1.5-flash-latest' => 'gemini-3-flash-preview',
        'gemini-1.5-flash-8b' => 'gemini-3.1-flash-lite',
        'gemini-1.5-pro' => 'gemini-3.1-pro-preview',
        'gemini-1.5-pro-latest' => 'gemini-3.1-pro-preview',
        'gemini-pro' => 'gemini-3-flash-preview',
        'gemini-2.0-flash' => 'gemini-3-flash-preview',
        'gemini-2.0-flash-lite' => 'gemini-3.1-flash-lite',
        'gemini-2.5-flash' => 'gemini-3-flash-preview',
        'gemini-2.5-pro' => 'gemini-3.1-pro-preview',
    ];

    /**
     * Thứ tự fallback khi model không tồn tại trên API key / region.
     *
     * @return list<string>
     */
    public static function fallbackModels(): array
    {
        return [
            'gemini-3-flash-preview',
            'gemini-3.1-flash-lite',
            'gemini-3.1-flash-lite-preview',
            'gemini-3.1-pro-preview',
            'gemini-3.5-flash-preview',
        ];
    }

    public static function defaultModel(): string
    {
        return 'gemini-3-flash-preview';
    }

    /**
     * @return array<string, string> value => label
     */
    public static function selectOptions(): array
    {
        return GoogleAiModelRegistry::textSelectOptions();
    }

    public static function resolve(string $model): string
    {
        $model = trim($model);

        if (str_starts_with($model, 'models/')) {
            $model = substr($model, 7);
        }

        return self::ALIASES[$model] ?? $model;
    }

    /**
     * @return list<string>
     */
    public static function modelsToTry(string $requestedModel): array
    {
        $primary = self::resolve($requestedModel !== '' ? $requestedModel : self::defaultModel());
        if (! GoogleAiModelRegistry::isTextModel($primary)) {
            $primary = self::defaultModel();
        }

        $candidates = array_values(array_unique(array_merge(
            [$primary],
            self::fallbackModels(),
        )));

        $candidates = array_values(array_filter(
            $candidates,
            static fn (string $model): bool => GoogleAiModelRegistry::isTextModel($model)
                && GeminiModelVersionPolicy::isEligibleForAutoRouting($model),
        ));

        return GeminiModelVersionPolicy::preferStableFirst($candidates);
    }
}
