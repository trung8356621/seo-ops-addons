<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordAnalysisStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordRelationshipType;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordReviewStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSource;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordRelationship;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Illuminate\Support\Facades\DB;

/**
 * Import keywords (list<string> hoặc rows) vào workspace — dedupe exact bằng
 * normalized_keyword, near-duplicate ghi nhận qua SeoKeywordRelationship.
 * Không bao giờ ghi đè field_sources thủ công của keyword đã tồn tại (import chỉ tạo mới).
 */
final class KeywordImportService
{
    public function __construct(
        private readonly KeywordNormalizationService $normalizer,
    ) {}

    /**
     * @param  list<string>|list<array<string, mixed>>  $rows
     * @return array{
     *   total: int,
     *   new: int,
     *   exact_duplicates: int,
     *   near_duplicates: int,
     *   rows: list<array<string, mixed>>
     * }
     */
    public function preview(SeoKeywordWorkspace $workspace, array $rows): array
    {
        $normalizedRows = $this->normalizeRows($rows);
        $existing = $this->existingNormalizedMap($workspace);

        $preview = [];
        $exact = 0;
        $near = 0;
        $seenInBatch = [];

        foreach ($normalizedRows as $row) {
            $normalized = $row['normalized_keyword'];
            $status = 'new';

            if ($normalized === '') {
                $status = 'invalid';
            } elseif (isset($existing[$normalized]) || isset($seenInBatch[$normalized])) {
                $status = 'exact_duplicate';
                $exact++;
            } else {
                $nearMatch = $this->findNearDuplicate($normalized, $existing);
                if ($nearMatch !== null) {
                    $status = 'near_duplicate';
                    $near++;
                }
            }

            if ($status === 'new') {
                $seenInBatch[$normalized] = true;
            }

            $preview[] = array_merge($row, ['status' => $status]);
        }

        return [
            'total' => count($preview),
            'new' => count(array_filter($preview, static fn (array $r): bool => $r['status'] === 'new')),
            'exact_duplicates' => $exact,
            'near_duplicates' => $near,
            'rows' => $preview,
        ];
    }

    /**
     * @param  list<string>|list<array<string, mixed>>  $rows
     * @return array{created: list<int>, exact_duplicates: int, near_duplicates: int, invalid: int}
     */
    public function import(
        SeoKeywordWorkspace $workspace,
        array $rows,
        bool $keepDuplicates = false,
        string $source = 'manual',
        ?int $importedBy = null,
    ): array {
        $normalizedRows = $this->normalizeRows($rows);
        $existing = $this->existingNormalizedMap($workspace);
        $sourceEnum = KeywordSource::tryFrom($source) ?? KeywordSource::Manual;

        $created = [];
        $exactDuplicates = 0;
        $nearDuplicates = 0;
        $invalid = 0;

        DB::connection('omi_seo_ai')->transaction(function () use (
            $normalizedRows,
            $existing,
            $workspace,
            $keepDuplicates,
            $sourceEnum,
            $importedBy,
            &$created,
            &$exactDuplicates,
            &$nearDuplicates,
            &$invalid,
        ): void {
            foreach ($normalizedRows as $row) {
                $normalized = $row['normalized_keyword'];
                if ($normalized === '') {
                    $invalid++;

                    continue;
                }

                $existingId = $existing[$normalized] ?? null;
                if ($existingId !== null) {
                    // `seo_keywords` có unique index (workspace_id, normalized_keyword) —
                    // exact duplicate KHÔNG BAO GIỜ tạo row mới. keep_duplicates=true chỉ
                    // cho phép backfill các metric còn thiếu (search_volume, cpc, ...) vào
                    // row hiện có, không ghi đè field_sources thủ công.
                    $exactDuplicates++;
                    if ($keepDuplicates) {
                        $this->backfillMissingMetrics($existingId, $row);
                    }

                    continue;
                }

                $nearMatchId = $this->findNearDuplicate($normalized, $existing);

                $keyword = $this->createKeywordRow($workspace, $row, $sourceEnum, $importedBy);
                $created[] = (int) $keyword->id;
                $existing[$normalized] = (int) $keyword->id;

                if ($nearMatchId !== null) {
                    $nearDuplicates++;
                    $this->createNearDuplicateRelationship($workspace, $nearMatchId, (int) $keyword->id);
                }
            }

            $workspace->keyword_count = SeoKiKeyword::query()->where('workspace_id', $workspace->id)->count();
            $workspace->save();
        });

        return [
            'created' => $created,
            'exact_duplicates' => $exactDuplicates,
            'near_duplicates' => $nearDuplicates,
            'invalid' => $invalid,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function backfillMissingMetrics(int $keywordId, array $row): void
    {
        $keyword = SeoKiKeyword::query()->find($keywordId);
        if (! $keyword instanceof SeoKiKeyword) {
            return;
        }

        $fieldSources = (array) ($keyword->field_sources ?? []);
        $dirty = false;

        foreach (['search_volume', 'keyword_difficulty', 'cpc', 'competition'] as $field) {
            if ($keyword->{$field} !== null) {
                continue;
            }
            // Không ghi đè nếu field đã được set thủ công (dù đang null — tôn trọng ý định user).
            if (($fieldSources[$field] ?? null) === 'manual') {
                continue;
            }
            if (($row[$field] ?? null) !== null) {
                $keyword->{$field} = $row[$field];
                $dirty = true;
            }
        }

        if ($dirty) {
            $keyword->save();
        }
    }

    private function createKeywordRow(
        SeoKeywordWorkspace $workspace,
        array $row,
        KeywordSource $source,
        ?int $importedBy,
    ): SeoKiKeyword {
        $keyword = new SeoKiKeyword([
            'public_ref' => 'pending',
            'workspace_id' => $workspace->id,
            'tenant_id' => $workspace->tenant_id,
            'site_id' => $workspace->site_id,
            'keyword' => $row['keyword'],
            'normalized_keyword' => $row['normalized_keyword'],
            'source' => $source->value,
            'search_volume' => $row['search_volume'] ?? null,
            'keyword_difficulty' => $row['keyword_difficulty'] ?? null,
            'cpc' => $row['cpc'] ?? null,
            'competition' => $row['competition'] ?? null,
            'analysis_status' => KeywordAnalysisStatus::Pending->value,
            'review_status' => KeywordReviewStatus::Unreviewed->value,
            'is_duplicate' => false,
            'is_primary' => false,
            'is_excluded' => false,
            'field_sources' => ['keyword' => 'import', 'source' => 'import'],
            'imported_by' => $importedBy,
        ]);
        $keyword->save();
        $keyword->public_ref = KeywordIntelligencePublicRef::keyword((int) $keyword->id);
        $keyword->save();

        return $keyword;
    }

    /**
     * @return array<string, int>
     */
    private function existingNormalizedMap(SeoKeywordWorkspace $workspace): array
    {
        return SeoKiKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->pluck('id', 'normalized_keyword')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, int>  $existing
     */
    private function findNearDuplicate(string $normalized, array $existing): ?int
    {
        foreach ($existing as $candidateNormalized => $id) {
            if ($this->normalizer->isNearDuplicate($normalized, (string) $candidateNormalized)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  list<string>|list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (is_string($row)) {
                $row = ['keyword' => $row];
            }

            if (! is_array($row)) {
                continue;
            }

            $keyword = trim((string) ($row['keyword'] ?? ''));
            if ($keyword === '') {
                continue;
            }

            $normalized[] = [
                'keyword' => $this->normalizer->displayKeyword($keyword),
                'normalized_keyword' => $this->normalizer->normalize($keyword),
                'search_volume' => isset($row['search_volume']) ? (int) $row['search_volume'] : null,
                'keyword_difficulty' => isset($row['keyword_difficulty']) ? (float) $row['keyword_difficulty'] : null,
                'cpc' => isset($row['cpc']) ? (float) $row['cpc'] : null,
                'competition' => isset($row['competition']) ? (float) $row['competition'] : null,
            ];
        }

        return $normalized;
    }

    private function createNearDuplicateRelationship(SeoKeywordWorkspace $workspace, int $keywordId, int $relatedKeywordId): void
    {
        $existing = SeoKeywordRelationship::query()
            ->where('workspace_id', $workspace->id)
            ->where('keyword_id', $keywordId)
            ->where('related_keyword_id', $relatedKeywordId)
            ->where('relationship_type', KeywordRelationshipType::NearDuplicate->value)
            ->exists();

        if ($existing) {
            return;
        }

        $relationship = new SeoKeywordRelationship([
            'public_ref' => 'pending',
            'workspace_id' => $workspace->id,
            'keyword_id' => $keywordId,
            'related_keyword_id' => $relatedKeywordId,
            'relationship_type' => KeywordRelationshipType::NearDuplicate->value,
            'confidence' => 0.85,
            'metadata' => ['detected_during' => 'import'],
        ]);
        $relationship->save();
        $relationship->public_ref = KeywordIntelligencePublicRef::relationship((int) $relationship->id);
        $relationship->save();
    }
}
