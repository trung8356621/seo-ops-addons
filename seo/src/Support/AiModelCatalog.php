<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use App\Models\ApiConnection;

/**
 * Facade cho UI — giá trị là Unified Category, không phải raw model slug.
 */
final class AiModelCatalog
{
    /**
     * @return array<string, string>
     */
    public static function optionsForConnection(?ApiConnection $connection): array
    {
        if ($connection === null) {
            return AiModelCategory::promptSelectOptions();
        }

        return AiModelCategory::connectionSelectOptions((string) $connection->provider);
    }

    /**
     * Gợi ý category khi chọn kết nối trên form Prompt (theo nhà cung cấp, không lưu trên kết nối).
     */
    public static function defaultForConnection(?ApiConnection $connection): string
    {
        if ($connection === null) {
            return AiModelCategory::GEMINI_FLASH;
        }

        return AiModelCategory::defaultForProvider((string) $connection->provider);
    }
}