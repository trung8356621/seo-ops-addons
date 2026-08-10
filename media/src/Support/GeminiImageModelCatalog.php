<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;

/**
 * @deprecated Dùng {@see GoogleAiModelRegistry::imageModelsToTry()}
 */
final class GeminiImageModelCatalog
{
    /**
     * @return list<string>
     */
    public static function modelsToTry(?string $requestedModel = null): array
    {
        return GoogleAiModelRegistry::imageModelsToTry($requestedModel);
    }

    public static function isNativeImageModel(string $model): bool
    {
        $category = GoogleAiModelRegistry::categoryOf($model);

        return in_array($category, [
            GoogleAiModelRegistry::CATEGORY_IMAGE_GEMINI,
            GoogleAiModelRegistry::CATEGORY_IMAGE_IMAGEN,
        ], true);
    }
}
