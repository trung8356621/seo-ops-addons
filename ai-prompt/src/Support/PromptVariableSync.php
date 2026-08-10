<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Support\PromptSiteContextVariable;

/**
 * Đồng bộ cột prompts.variables từ placeholder {{name}} trong markdown_content.
 */
final class PromptVariableSync
{
    /**
     * @return array<int, string>
     */
    public static function extractNames(string $markdown): array
    {
        if (! preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $markdown, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Gộp biến khai báo thủ công với biến dò từ markdown; giữ ghi chú đã nhập.
     *
     * @param  array<int, array<string, mixed>>|null  $declared
     * @return array<int, array{name: string, description: ?string}>
     */
    public static function mergeFromMarkdown(string $markdown, ?array $declared): array
    {
        $declared = PromptResource::sanitizeDeclaredVariables($declared);
        $declaredByName = collect($declared)->keyBy(
            static fn (array $row): string => trim((string) ($row['name'] ?? '')),
        );

        $defaultLabels = PromptResource::defaultVariableLabels();
        $merged = [];

        foreach (self::extractNames($markdown) as $name) {
            if (PromptLoaiSanPhamVariable::isLoaiSanPhamName($name)
                || PromptSiteContextVariable::isName($name)
                || strtoupper($name) === 'PARENT_RESULT') {
                continue;
            }

            $row = $declaredByName->get($name);
            $description = filled($row['description'] ?? null)
                ? (string) $row['description']
                : ($defaultLabels[$name] ?? null);

            $merged[] = [
                'name' => $name,
                'description' => $description !== '' ? $description : null,
            ];
        }

        $mergedNames = collect($merged)->pluck('name')->all();

        foreach ($declared as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' || in_array($name, $mergedNames, true)) {
                continue;
            }

            $merged[] = [
                'name' => $name,
                'description' => filled($row['description'] ?? null) ? (string) $row['description'] : null,
            ];
        }

        return collect($merged)
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
