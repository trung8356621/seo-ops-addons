<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;

/**
 * Exact-duplicate resolution theo scope tenant+site+workspace+normalized_keyword.
 * Không tạo row mới khi đã có canonical — chỉ backfill metric còn thiếu vào canonical,
 * KHÔNG BAO GIỜ ghi đè field đã được đánh dấu field_sources = manual.
 */
final class KeywordDuplicateResolver
{
    /**
     * Field được phép backfill từ dữ liệu import vào keyword canonical.
     * Không gồm 'keyword'/'normalized_keyword' — canonical đã đúng theo định nghĩa (exact match).
     *
     * @var list<string>
     */
    private const MERGEABLE_METRIC_FIELDS = [
        'search_volume',
        'keyword_difficulty',
        'cpc',
        'competition',
    ];

    public function __construct(
        private readonly KeywordManualOverrideGuard $overrideGuard = new KeywordManualOverrideGuard,
    ) {}

    /**
     * Tìm keyword canonical trùng khớp chính xác (tenant+site+workspace+normalized).
     * Nếu tìm thấy, tự động merge metadata/metric còn thiếu (không ghi đè manual) rồi trả về canonical
     * — caller KHÔNG được tạo row mới trong trường hợp này.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function resolveExact(
        SeoKeywordWorkspace $workspace,
        string $normalized,
        string $display,
        array $metadata = [],
    ): ?SeoKiKeyword {
        if ($normalized === '') {
            return null;
        }

        $existing = SeoKiKeyword::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('site_id', $workspace->site_id)
            ->where('workspace_id', $workspace->id)
            ->where('normalized_keyword', $normalized)
            ->where('is_duplicate', false)
            ->orderBy('id')
            ->first();

        if (! $existing instanceof SeoKiKeyword) {
            return null;
        }

        unset($display);
        $this->mergeIntoCanonical($existing, $metadata);

        return $existing;
    }

    /**
     * Đánh dấu $duplicate là bản sao của $canonical — dùng khi caller đã insert row
     * trước khi phát hiện trùng (ví dụ batch import song song).
     */
    public function markDuplicate(SeoKiKeyword $duplicate, SeoKiKeyword $canonical): void
    {
        if ((int) $duplicate->id === (int) $canonical->id) {
            return;
        }

        $duplicate->is_duplicate = true;
        $duplicate->duplicate_of_keyword_id = $canonical->id;
        $duplicate->save();
    }

    /**
     * Merge dữ liệu "incoming" vào keyword canonical.
     * Quy tắc: chỉ backfill field còn null trên canonical, và không bao giờ ghi đè
     * field đã có field_sources[field] === 'manual'.
     *
     * @param  array<string, mixed>  $incoming
     */
    public function mergeIntoCanonical(SeoKiKeyword $canonical, array $incoming): void
    {
        $fieldSources = (array) ($canonical->field_sources ?? []);
        $dirty = false;

        foreach (self::MERGEABLE_METRIC_FIELDS as $field) {
            if (! array_key_exists($field, $incoming) || $incoming[$field] === null) {
                continue;
            }
            if ($canonical->{$field} !== null) {
                continue;
            }
            if ($this->overrideGuard->isManual($fieldSources, $field)) {
                continue;
            }

            $canonical->{$field} = $incoming[$field];
            $dirty = true;
        }

        $metadata = (array) ($canonical->metadata ?? []);

        if (isset($incoming['metadata']) && is_array($incoming['metadata'])) {
            // array_merge ưu tiên giá trị đã tồn tại trên canonical — không ghi đè key đã có.
            $merged = array_merge($incoming['metadata'], $metadata);
            if ($merged !== $metadata) {
                $metadata = $merged;
                $dirty = true;
            }
        }

        if (isset($incoming['source_ref']) && is_string($incoming['source_ref']) && trim($incoming['source_ref']) !== '') {
            $sourceRefs = (array) ($metadata['source_refs'] ?? []);
            $sourceRefs[] = trim($incoming['source_ref']);
            $metadata['source_refs'] = array_values(array_unique($sourceRefs));
            $dirty = true;
        }

        $metadata['duplicate_merge_count'] = ((int) ($metadata['duplicate_merge_count'] ?? 0)) + 1;
        $canonical->metadata = $metadata;
        $dirty = true;

        if ($dirty) {
            $canonical->save();
        }
    }
}
