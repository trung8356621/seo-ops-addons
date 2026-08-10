<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Ánh xạ tên model trong AI Studio → slug API + loại endpoint.
 *
 * @see https://ai.google.dev/gemini-api/docs/models
 * @see https://ai.google.dev/gemini-api/docs/imagen
 * @see https://ai.google.dev/gemini-api/docs/image-generation
 * @see https://ai.google.dev/gemini-api/docs/video
 */
final class GoogleAiModelRegistry
{
    public const CATEGORY_TEXT = 'text';

    public const CATEGORY_IMAGE_GEMINI = 'image_gemini';

    public const CATEGORY_IMAGE_IMAGEN = 'image_imagen';

    public const CATEGORY_VIDEO = 'video';

    public const CATEGORY_AUDIO_TTS = 'audio_tts';

    public const CATEGORY_EMBEDDING = 'embedding';

    public const CATEGORY_OTHER = 'other';

    /**
     * slug => [label, category, endpoint]
     * endpoint: generateContent | predict | predictLongRunning | unsupported
     *
     * @var array<string, array{label: string, category: string, endpoint: string}>
     */
    private const MODELS = [
        // --- Text (generateContent) ---
        'gemini-3.1-pro-preview' => ['label' => 'Gemini 3.1 Pro', 'category' => self::CATEGORY_TEXT, 'endpoint' => 'generateContent'],
        'gemini-3-flash-preview' => ['label' => 'Gemini 3 Flash', 'category' => self::CATEGORY_TEXT, 'endpoint' => 'generateContent'],
        'gemini-3.5-flash-preview' => ['label' => 'Gemini 3.5 Flash', 'category' => self::CATEGORY_TEXT, 'endpoint' => 'generateContent'],
        'gemini-3.1-flash-lite-preview' => ['label' => 'Gemini 3.1 Flash Lite', 'category' => self::CATEGORY_TEXT, 'endpoint' => 'generateContent'],
        'gemini-3.1-flash-lite' => ['label' => 'Gemini 3.1 Flash Lite', 'category' => self::CATEGORY_TEXT, 'endpoint' => 'generateContent'],
        'gemini-2.5-flash' => ['label' => 'Gemini 2.5 Flash', 'category' => self::CATEGORY_TEXT, 'endpoint' => 'generateContent'],
        'gemini-2.5-pro' => ['label' => 'Gemini 2.5 Pro', 'category' => self::CATEGORY_TEXT, 'endpoint' => 'generateContent'],
        'gemini-2.5-flash-lite' => ['label' => 'Gemini 2.5 Flash Lite', 'category' => self::CATEGORY_TEXT, 'endpoint' => 'generateContent'],
        'gemini-2.0-flash' => ['label' => 'Gemini 2 Flash', 'category' => self::CATEGORY_TEXT, 'endpoint' => 'generateContent'],
        'gemini-2.0-flash-lite' => ['label' => 'Gemini 2 Flash Lite', 'category' => self::CATEGORY_TEXT, 'endpoint' => 'generateContent'],
        'gemini-2.0-flash-thinking-exp' => ['label' => 'Gemini 2 (thinking)', 'category' => self::CATEGORY_TEXT, 'endpoint' => 'generateContent'],

        // --- Image: Imagen 4 (predict) ---
        'imagen-4.0-generate-001' => ['label' => 'Imagen 4 Generate', 'category' => self::CATEGORY_IMAGE_IMAGEN, 'endpoint' => 'predict'],
        'imagen-4.0-ultra-generate-001' => ['label' => 'Imagen 4 Ultra Generate', 'category' => self::CATEGORY_IMAGE_IMAGEN, 'endpoint' => 'predict'],
        'imagen-4.0-fast-generate-001' => ['label' => 'Imagen 4 Fast Generate', 'category' => self::CATEGORY_IMAGE_IMAGEN, 'endpoint' => 'predict'],

        // --- Image: Nano Banana / Gemini native image (generateContent, v1beta) ---
        'gemini-3.1-flash-image-preview' => ['label' => 'Nano Banana 2 (Gemini 3.1 Flash Image)', 'category' => self::CATEGORY_IMAGE_GEMINI, 'endpoint' => 'generateContent'],
        'gemini-3-pro-image-preview' => ['label' => 'Nano Banana Pro (Gemini 3 Pro Image)', 'category' => self::CATEGORY_IMAGE_GEMINI, 'endpoint' => 'generateContent'],
        'gemini-2.5-flash-image' => ['label' => 'Nano Banana (Gemini 2.5 Flash Image)', 'category' => self::CATEGORY_IMAGE_GEMINI, 'endpoint' => 'generateContent'],
        'gemini-2.5-pro-image' => ['label' => 'Nano Banana Pro (Gemini 2.5 Pro Image)', 'category' => self::CATEGORY_IMAGE_GEMINI, 'endpoint' => 'generateContent'],

        // --- Video: Veo (predictLongRunning) ---
        'veo-3.1-generate-preview' => ['label' => 'Veo 3.1 Generate', 'category' => self::CATEGORY_VIDEO, 'endpoint' => 'predictLongRunning'],
        'veo-3.1-fast-generate-preview' => ['label' => 'Veo 3.1 Fast Generate', 'category' => self::CATEGORY_VIDEO, 'endpoint' => 'predictLongRunning'],
        'veo-3.0-generate-preview' => ['label' => 'Veo 3 Generate', 'category' => self::CATEGORY_VIDEO, 'endpoint' => 'predictLongRunning'],
        'veo-3.0-fast-generate-preview' => ['label' => 'Veo 3 Fast Generate', 'category' => self::CATEGORY_VIDEO, 'endpoint' => 'predictLongRunning'],

        // --- TTS / Embedding (chưa tích hợp prompt runner) ---
        'gemini-2.5-flash-preview-tts' => ['label' => 'Gemini 2.5 Flash TTS', 'category' => self::CATEGORY_AUDIO_TTS, 'endpoint' => 'unsupported'],
        'gemini-2.5-pro-preview-tts' => ['label' => 'Gemini 2.5 Pro TTS', 'category' => self::CATEGORY_AUDIO_TTS, 'endpoint' => 'unsupported'],
        'gemini-embedding-001' => ['label' => 'Gemini Embedding 1', 'category' => self::CATEGORY_EMBEDDING, 'endpoint' => 'unsupported'],
        'text-embedding-004' => ['label' => 'Gemini Embedding 2', 'category' => self::CATEGORY_EMBEDDING, 'endpoint' => 'unsupported'],
    ];

    public static function categoryOf(string $modelSlug): string
    {
        $slug = self::normalizeSlug($modelSlug);
        $row = self::MODELS[$slug] ?? null;

        if ($row !== null) {
            return $row['category'];
        }

        if (str_starts_with($slug, 'imagen-')) {
            return self::CATEGORY_IMAGE_IMAGEN;
        }

        if (str_contains($slug, 'image') || str_contains($slug, 'imagen')) {
            return self::CATEGORY_IMAGE_GEMINI;
        }

        if (str_starts_with($slug, 'veo-')) {
            return self::CATEGORY_VIDEO;
        }

        if (str_contains($slug, 'tts') || str_contains($slug, 'lyria')) {
            return self::CATEGORY_AUDIO_TTS;
        }

        if (str_contains($slug, 'embed')) {
            return self::CATEGORY_EMBEDDING;
        }

        return self::CATEGORY_TEXT;
    }

    public static function isImagenModel(string $modelSlug): bool
    {
        return self::categoryOf($modelSlug) === self::CATEGORY_IMAGE_IMAGEN;
    }

    public static function isGeminiNativeImageModel(string $modelSlug): bool
    {
        return self::categoryOf($modelSlug) === self::CATEGORY_IMAGE_GEMINI;
    }

    public static function isTextModel(string $modelSlug): bool
    {
        return self::categoryOf($modelSlug) === self::CATEGORY_TEXT;
    }

    public static function isRegistered(string $modelSlug): bool
    {
        $slug = self::normalizeSlug($modelSlug);

        return $slug !== '' && isset(self::MODELS[$slug]);
    }

    /**
     * Thứ tự thử khi công cụ prompt = Hình ảnh.
     *
     * Ưu tiên Nano Banana (Gemini native image) trước: model này hiểu prompt
     * hướng dẫn dài (grid layout, mô tả tiếng Việt) và vẽ sản phẩm thay vì
     * render chữ trong prompt thành ảnh như Imagen.
     *
     * $excludeImagen = true (ảnh sản phẩm): Imagen render chữ trong prompt
     * thành ảnh text → loại hẳn khỏi danh sách, chỉ dùng Nano Banana.
     *
     * @return list<string>
     */
    /**
     * @deprecated Dùng ImageRoutingStrategy::modelsToTry — wrapper BC cho test/legacy.
     *
     * @return list<string>
     */
    public static function imageModelsToTry(
        ?string $preferred = null,
        bool $excludeImagen = false,
        ?array $customPriority = null,
        ?int $inputLength = null,
    ): array {
        $models = (new ImageRoutingStrategy())->modelsToTry(
            toolType: ImageToolType::Image,
            preference: RenderingPreference::Balanced,
            compiledPromptLength: $inputLength,
            productContext: $excludeImagen,
            configuredPriorityList: $customPriority,
        );

        $preferred = self::normalizeSlug((string) $preferred);
        if ($preferred !== '' && self::categoryOf($preferred) !== self::CATEGORY_TEXT) {
            $models = array_values(array_unique(array_merge([$preferred], $models)));
        }

        return $models;
    }

    /**
     * @param  list<string|array{slug?: string}>|null  $customPriority
     * @return list<string>
     */
    public static function resolveImageModelPriorityList(?array $customPriority): array
    {
        return self::resolveImageModelPriority($customPriority);
    }

    /**
     * @return list<string>
     */
    public static function defaultImageModelPriority(): array
    {
        // Gemini major < 3 không vào auto-routing (xem GeminiModelVersionPolicy).
        return [
            'gemini-3.1-flash-image-preview',
            'gemini-3-pro-image-preview',
            'imagen-4.0-generate-001',
        ];
    }

    /**
     * @param  list<string|array{slug?: string}>|null  $customPriority
     * @return list<string>
     */
    private static function resolveImageModelPriority(?array $customPriority): array
    {
        if ($customPriority === null || $customPriority === []) {
            return self::defaultImageModelPriority();
        }

        $normalized = [];

        foreach ($customPriority as $item) {
            $slug = is_string($item)
                ? trim($item)
                : trim((string) (is_array($item) ? ($item['slug'] ?? '') : ''));

            if ($slug === '') {
                continue;
            }

            $slug = self::normalizeSlug($slug);
            if ($slug === '' || self::categoryOf($slug) === self::CATEGORY_TEXT) {
                continue;
            }

            if (! GeminiModelVersionPolicy::isEligibleForAutoRouting($slug)) {
                continue;
            }

            $normalized[] = $slug;
        }

        $normalized = array_values(array_unique($normalized));

        return $normalized !== [] ? $normalized : self::defaultImageModelPriority();
    }

    /**
     * Chỉ model text — dùng dropdown kết nối AI / chạy thử văn bản.
     *
     * @return array<string, string>
     */
    public static function textSelectOptions(): array
    {
        $options = [];
        foreach (self::MODELS as $slug => $row) {
            if ($row['category'] === self::CATEGORY_TEXT) {
                $options[$slug] = $row['label'].' — văn bản';
            }
        }

        return $options;
    }

    /**
     * Model sinh ảnh — tham khảo khi công cụ = Hình ảnh.
     *
     * @return array<string, string>
     */
    public static function imageSelectOptions(): array
    {
        $options = [];
        foreach (self::MODELS as $slug => $row) {
            if (in_array($row['category'], [self::CATEGORY_IMAGE_IMAGEN, self::CATEGORY_IMAGE_GEMINI], true)) {
                $suffix = $row['category'] === self::CATEGORY_IMAGE_IMAGEN ? 'Imagen' : 'Nano Banana';
                $options[$slug] = $row['label'].' — '.$suffix;
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function videoSelectOptions(): array
    {
        $options = [];
        foreach (self::MODELS as $slug => $row) {
            if ($row['category'] === self::CATEGORY_VIDEO) {
                $options[$slug] = $row['label'].' — video (async)';
            }
        }

        return $options;
    }

    public static function normalizeSlug(string $model): string
    {
        $model = trim($model);
        if (str_starts_with($model, 'models/')) {
            $model = substr($model, 7);
        }

        return GeminiModelCatalog::resolve($model);
    }

    /**
     * Gợi ý model mặc định lưu trên ApiConnection theo công cụ prompt.
     */
    public static function suggestedDefaultForTool(string $tool): ?string
    {
        return match ($tool) {
            'image' => 'imagen-4.0-fast-generate-001',
            'video' => 'veo-3.1-fast-generate-preview',
            default => GeminiModelCatalog::defaultModel(),
        };
    }
}
