<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;

/**
 * Biến loai_san_pham — chỉ dùng cho post_type product / product_cat.
 */
final class PromptLoaiSanPhamVariable
{
    public const NAME = 'loai_san_pham';

    public const SITE_FIELD = '_loai_san_pham_site_id';

    public const CATEGORY_FIELD = '_loai_san_pham_category_article_id';

    public const CUSTOM_FIELD = 'loai_san_pham_custom';

    /**
     * @return list<string>
     */
    public static function aliases(): array
    {
        return [
            self::NAME,
            'LOAI_SAN_PHAM',
            'loai_san_pham',
        ];
    }

    public static function isLoaiSanPhamName(string $name): bool
    {
        return strtolower(trim($name)) === self::NAME;
    }

    public static function usesInPrompt(SeoPrompt $prompt): bool
    {
        foreach (PromptResource::variableDefinitionsForPrompt($prompt) as $definition) {
            if (self::isLoaiSanPhamName((string) ($definition['name'] ?? ''))) {
                return true;
            }
        }

        foreach (PromptResource::extractVariableNamesFromMarkdown((string) ($prompt->markdown_content ?? '')) as $name) {
            if (self::isLoaiSanPhamName($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function withAliases(array $values): array
    {
        $canonical = trim((string) ($values[self::NAME] ?? ''));
        if ($canonical === '') {
            return array_map(
                static fn ($value): string => is_string($value) ? $value : (string) $value,
                $values,
            );
        }

        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[(string) $key] = is_string($value) ? $value : (string) $value;
        }

        foreach (self::aliases() as $alias) {
            $normalized[$alias] = $canonical;
        }

        return $normalized;
    }

    /**
     * Ghép giá trị cuối cho {{loai_san_pham}} từ site + danh mục + custom.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function mergeIntoVariables(array $values): array
    {
        $merged = [];
        foreach ($values as $key => $value) {
            $merged[(string) $key] = is_string($value) ? $value : (string) $value;
        }

        $merged[self::NAME] = app(\Omnichannel\Addons\AiPrompt\Services\PromptLoaiSanPhamOptionsService::class)
            ->buildCompositeValue(
                (int) ($merged[self::SITE_FIELD] ?? 0),
                (int) ($merged[self::CATEGORY_FIELD] ?? 0),
                trim((string) ($merged[self::CUSTOM_FIELD] ?? '')),
            );

        return $merged;
    }
}
