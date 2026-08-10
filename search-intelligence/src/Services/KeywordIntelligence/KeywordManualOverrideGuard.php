<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;

/**
 * Guard tập trung cho quy ước field_sources[$field] === 'manual' — mọi service ghi đè
 * dữ liệu keyword (import, scoring, clustering, AI intent...) PHẢI hỏi qua đây trước khi ghi.
 */
final class KeywordManualOverrideGuard
{
    /**
     * @param  SeoKiKeyword|array<string, mixed>  $keywordOrFieldSources
     */
    public function isManual(SeoKiKeyword|array $keywordOrFieldSources, string $field): bool
    {
        $fieldSources = $keywordOrFieldSources instanceof SeoKiKeyword
            ? (array) ($keywordOrFieldSources->field_sources ?? [])
            : $keywordOrFieldSources;

        $source = $fieldSources[$field] ?? null;
        if (is_array($source)) {
            $source = $source['source'] ?? null;
        }

        return $source === 'manual';
    }

    /**
     * Đánh dấu 1 field là manual — dùng khi user chỉnh sửa trực tiếp trên Filament/API.
     *
     * @param  array<string, mixed>  $fieldSources
     * @return array<string, mixed>
     */
    public function touchManual(array &$fieldSources, string $field, ?string $actorRef = null): array
    {
        $fieldSources[$field] = 'manual';

        if ($actorRef !== null && trim($actorRef) !== '') {
            $fieldSources[$field.'_actor'] = trim($actorRef);
            $fieldSources[$field.'_touched_at'] = function_exists('now') ? now()->toIso8601String() : date('c');
        }

        return $fieldSources;
    }
}
