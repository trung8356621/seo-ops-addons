<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Support\ArticleRequiredDataRegistry;
use Omnichannel\Addons\Content\Support\ArticleSeoInventoryPolicy;

/**
 * Local-only aggregate audit of ArticleRequiredDataRegistry fields (no WP HTTP).
 *
 * Distinguishes PRESENT / MISSING / NOT_APPLICABLE / SOURCE_ABSENT_EXPECTED.
 * Only MISSING contributes to warning severity.
 */
final class ArticleRequiredDataHealthAuditor
{
    public const OUTCOME_PRESENT = 'present';

    public const OUTCOME_MISSING = 'missing';

    public const OUTCOME_NOT_APPLICABLE = 'not_applicable';

    public const OUTCOME_SOURCE_ABSENT = 'source_absent_expected';

    /**
     * @return array{
     *   total: int,
     *   seo_inventory_total: int,
     *   by_content_type: array{post: int, page: int, product: int, other: int},
     *   fields: list<array{
     *     key: string,
     *     label: string,
     *     present: int,
     *     missing: int,
     *     not_applicable: int,
     *     source_absent: int,
     *     applicable: int,
     *     total: int,
     *     severity: string,
     *     how_to_check: string,
     *     storage: string,
     *     technical_key: string
     *   }>,
     *   worst_severity: string,
     *   max_missing: int
     * }
     */
    public function audit(int $siteId, ?string $language = null): array
    {
        $summary = $this->aggregateCandidates($siteId, $language);
        $seoInventoryTotal = $summary['total'];
        $byType = $summary['by_content_type'];
        $fields = [];
        $maxMissing = 0;
        $worst = ArticleRequiredDataRegistry::SEVERITY_GREEN;

        foreach (ArticleRequiredDataRegistry::all() as $def) {
            $bucket = $summary['fields'][$def['key']] ?? [
                self::OUTCOME_PRESENT => 0,
                self::OUTCOME_MISSING => 0,
                self::OUTCOME_NOT_APPLICABLE => 0,
                self::OUTCOME_SOURCE_ABSENT => 0,
            ];

            $present = $bucket[self::OUTCOME_PRESENT];
            $missing = $bucket[self::OUTCOME_MISSING];
            $notApplicable = $bucket[self::OUTCOME_NOT_APPLICABLE];
            $sourceAbsent = $bucket[self::OUTCOME_SOURCE_ABSENT];
            // Denominator for UI: cases where the field is in scope (excludes N/A).
            $applicable = $present + $missing + $sourceAbsent;
            // Healthy share treats expected source-absent as complete.
            $healthy = $present + $sourceAbsent;
            $severity = ArticleRequiredDataRegistry::severityForMissing($missing);
            $maxMissing = max($maxMissing, $missing);
            $worst = $this->worseSeverity($worst, $severity);

            $technicalKey = match ($def['storage']) {
                'column' => (string) ($def['column'] ?? $def['key']),
                'meta' => (string) ($def['meta_key'] ?? $def['key']),
                'relation' => (string) ($def['relation'] ?? $def['key']),
                default => $def['key'],
            };

            $fields[] = [
                'key' => $def['key'],
                'label' => $def['label'],
                'present' => $healthy,
                'missing' => $missing,
                'not_applicable' => $notApplicable,
                'source_absent' => $sourceAbsent,
                'applicable' => $applicable,
                'total' => $applicable,
                'raw_present' => $present,
                'severity' => $severity,
                'how_to_check' => $def['how_to_check'],
                'storage' => $def['storage'],
                'technical_key' => $technicalKey,
            ];
        }

        return [
            'total' => $seoInventoryTotal,
            'seo_inventory_total' => $seoInventoryTotal,
            'by_content_type' => $byType,
            'fields' => $fields,
            'worst_severity' => $worst,
            'max_missing' => $maxMissing,
        ];
    }

    /**
     * Exact article IDs with true MISSING for a field (applicable only).
     *
     * @return list<int>
     */
    public function missingArticleIds(int $siteId, string $fieldKey): array
    {
        $ids = [];
        foreach ($this->loadCandidateRows($siteId) as $row) {
            if ($this->classifyField($fieldKey, $row) === self::OUTCOME_MISSING) {
                $ids[] = (int) $row['article_id'];
            }
        }

        return $ids;
    }

    /**
     * @return list<array{
     *   article_id: int,
     *   title: ?string,
     *   slug: ?string,
     *   status: ?string,
     *   wp_post_id: ?int,
     *   content_type: ?string,
     *   wp_post_type: ?string,
     *   wp_is_term: ?string,
     *   wp_permalink: ?string
     * }>
     */
    private function loadCandidateRows(int $siteId, ?string $language = null): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return [];
        }

        $language = $language !== null ? trim($language) : '';

        $q = DB::connection('omi_seo_ai')
            ->table('articles as a')
            ->leftJoin('wordpress_article_links as wal', 'wal.article_id', '=', 'a.id')
            ->leftJoin('article_meta as am_ct', function ($j): void {
                $j->on('am_ct.article_id', '=', 'a.id')->where('am_ct.meta_key', '=', 'content_type');
            })
            ->leftJoin('article_meta as am_pt', function ($j): void {
                $j->on('am_pt.article_id', '=', 'a.id')->where('am_pt.meta_key', '=', 'wp_post_type');
            })
            ->leftJoin('article_meta as am_term', function ($j): void {
                $j->on('am_term.article_id', '=', 'a.id')->where('am_term.meta_key', '=', 'wp_is_term');
            })
            ->leftJoin('article_meta as am_perm', function ($j): void {
                $j->on('am_perm.article_id', '=', 'a.id')->where('am_perm.meta_key', '=', 'wp_permalink');
            })
            ->where('a.site_id', $siteId)
            ->whereNull('a.deleted_at')
            ->select([
                'a.id as article_id',
                'a.title',
                'a.slug',
                'a.status',
                'a.language',
                'wal.wp_post_id',
                'am_ct.meta_value as content_type',
                'am_pt.meta_value as wp_post_type',
                'am_term.meta_value as wp_is_term',
                'am_perm.meta_value as wp_permalink',
            ]);

        if ($language !== '') {
            $q->where('a.language', $language);
        }

        $out = [];
        foreach ($q->get() as $row) {
            $wpPostType = $row->wp_post_type !== null ? (string) $row->wp_post_type : null;
            $wpIsTerm = $row->wp_is_term !== null ? (string) $row->wp_is_term : null;
            if (! ArticleSeoInventoryPolicy::isSeoInventoryCandidate($wpPostType, $wpIsTerm)) {
                continue;
            }
            $out[] = [
                'article_id' => (int) $row->article_id,
                'title' => $row->title !== null ? (string) $row->title : null,
                'slug' => $row->slug !== null ? (string) $row->slug : null,
                'status' => $row->status !== null ? (string) $row->status : null,
                'wp_post_id' => $row->wp_post_id !== null ? (int) $row->wp_post_id : null,
                'content_type' => $row->content_type !== null ? (string) $row->content_type : null,
                'wp_post_type' => $wpPostType,
                'wp_is_term' => $wpIsTerm,
                'wp_permalink' => $row->wp_permalink !== null ? (string) $row->wp_permalink : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array{
     *   article_id: int,
     *   title: ?string,
     *   slug: ?string,
     *   status: ?string,
     *   wp_post_id: ?int,
     *   content_type: ?string,
     *   wp_post_type: ?string,
     *   wp_is_term: ?string,
     *   wp_permalink: ?string
     * }  $row
     */
    private function classifyField(string $key, array $row): string
    {
        $wpBacked = ArticleSeoInventoryPolicy::isWpBacked($row['wp_post_id'] ?? null);

        return match ($key) {
            'source_id' => $this->classifySourceId($row, $wpBacked),
            'title' => $this->filled($row['title'] ?? null) ? self::OUTCOME_PRESENT : self::OUTCOME_MISSING,
            'slug' => $this->classifySlug($row, $wpBacked),
            'permalink' => $this->classifyPermalink($row, $wpBacked),
            'content_type' => $this->classifyContentType($row),
            'wp_post_type' => $this->classifyWpPostType($row, $wpBacked),
            'status' => $this->filled($row['status'] ?? null) ? self::OUTCOME_PRESENT : self::OUTCOME_MISSING,
            default => self::OUTCOME_NOT_APPLICABLE,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function classifySourceId(array $row, bool $wpBacked): string
    {
        if (! $wpBacked) {
            return self::OUTCOME_NOT_APPLICABLE;
        }

        return self::OUTCOME_PRESENT;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function classifySlug(array $row, bool $wpBacked): string
    {
        if ($this->filled($row['slug'] ?? null)) {
            return self::OUTCOME_PRESENT;
        }

        // Draft/pending commonly have empty post_name on WP — expected, not importer loss.
        if (ArticleSeoInventoryPolicy::isDraftish($row['status'] ?? null)) {
            return self::OUTCOME_SOURCE_ABSENT;
        }

        // Local-only without slug: still structural for SEO Ops local inventory.
        if (! $wpBacked) {
            return self::OUTCOME_MISSING;
        }

        return self::OUTCOME_MISSING;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function classifyPermalink(array $row, bool $wpBacked): string
    {
        if (! $wpBacked) {
            return self::OUTCOME_NOT_APPLICABLE;
        }

        if ($this->filled($row['wp_permalink'] ?? null)) {
            return self::OUTCOME_PRESENT;
        }

        // Draftish may lack stored permalink meta in edge cases.
        if (ArticleSeoInventoryPolicy::isDraftish($row['status'] ?? null)) {
            return self::OUTCOME_SOURCE_ABSENT;
        }

        return self::OUTCOME_MISSING;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function classifyContentType(array $row): string
    {
        $ct = strtolower(trim((string) ($row['content_type'] ?? '')));
        $allowed = array_map(
            static fn (ContentType $t): string => $t->value,
            ContentType::cases(),
        );
        if ($ct !== '' && in_array($ct, $allowed, true)) {
            return self::OUTCOME_PRESENT;
        }

        return self::OUTCOME_MISSING;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function classifyWpPostType(array $row, bool $wpBacked): string
    {
        if (! $wpBacked) {
            return self::OUTCOME_NOT_APPLICABLE;
        }

        return $this->filled($row['wp_post_type'] ?? null)
            ? self::OUTCOME_PRESENT
            : self::OUTCOME_MISSING;
    }

    private function filled(?string $value): bool
    {
        return trim((string) $value) !== '';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{post: int, page: int, product: int, other: int}
     */
    /**
     * @return array{
     *   total: int,
     *   by_content_type: array{post: int, page: int, product: int, other: int},
     *   fields: array<string, array{present: int, missing: int, not_applicable: int, source_absent_expected: int}>
     * }
     */
    private function aggregateCandidates(int $siteId, ?string $language): array
    {
        $emptyFields = [];
        foreach (ArticleRequiredDataRegistry::keys() as $key) {
            $emptyFields[$key] = [
                self::OUTCOME_PRESENT => 0,
                self::OUTCOME_MISSING => 0,
                self::OUTCOME_NOT_APPLICABLE => 0,
                self::OUTCOME_SOURCE_ABSENT => 0,
            ];
        }
        $empty = [
            'total' => 0,
            'by_content_type' => ['post' => 0, 'page' => 0, 'product' => 0, 'other' => 0],
            'fields' => $emptyFields,
        ];
        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return $empty;
        }

        $systemTypes = array_map(
            static fn (string $type): string => strtolower($type),
            ArticleSeoInventoryPolicy::SYSTEM_WP_POST_TYPES,
        );
        $systemPlaceholders = implode(',', array_fill(0, count($systemTypes), '?'));
        $quote = static fn (string $value): string => "'".str_replace("'", "''", $value)."'";
        $contentSql = implode(',', array_map($quote, ContentType::values()));
        $draftSql = implode(',', array_map($quote, ArticleSeoInventoryPolicy::DRAFTISH_STATUSES));
        $language = $language !== null ? trim($language) : '';

        $inner = DB::connection('omi_seo_ai')
            ->table('articles as a')
            ->leftJoin('wordpress_article_links as wal', 'wal.article_id', '=', 'a.id')
            ->leftJoin('article_meta as am', function ($join): void {
                $join->on('am.article_id', '=', 'a.id')
                    ->whereIn('am.meta_key', ['content_type', 'wp_post_type', 'wp_is_term', 'wp_permalink']);
            })
            ->where('a.site_id', $siteId)
            ->whereNull('a.deleted_at')
            ->when($language !== '', fn ($query) => $query->where('a.language', $language))
            ->groupBy('a.id', 'a.title', 'a.slug', 'a.status')
            ->select('a.id')
            ->selectRaw('MAX(a.title) as title')
            ->selectRaw('MAX(a.slug) as slug')
            ->selectRaw('MAX(a.status) as status')
            ->selectRaw('MAX(wal.wp_post_id) as wp_post_id')
            ->selectRaw("MAX(CASE WHEN am.meta_key = 'content_type' THEN am.meta_value END) as content_type")
            ->selectRaw("MAX(CASE WHEN am.meta_key = 'wp_post_type' THEN am.meta_value END) as wp_post_type")
            ->selectRaw("MAX(CASE WHEN am.meta_key = 'wp_is_term' THEN am.meta_value END) as wp_is_term")
            ->selectRaw("MAX(CASE WHEN am.meta_key = 'wp_permalink' THEN am.meta_value END) as wp_permalink");

        $filled = static fn (string $column): string => "TRIM(COALESCE({$column}, '')) <> ''";
        $wp = 'wp_post_id IS NOT NULL AND wp_post_id > 0';
        $draft = "LOWER(TRIM(COALESCE(status, ''))) IN ({$draftSql})";
        $ctOk = "LOWER(TRIM(COALESCE(content_type, ''))) IN ({$contentSql})";

        $row = DB::connection('omi_seo_ai')
            ->query()
            ->fromSub($inner, 'candidate')
            ->whereRaw("LOWER(TRIM(COALESCE(wp_is_term, ''))) NOT IN ('1', 'true', 'yes')")
            ->where(function ($query) use ($systemTypes, $systemPlaceholders): void {
                $query->whereRaw("TRIM(COALESCE(wp_post_type, '')) = ''")
                    ->orWhere(function ($typed) use ($systemTypes, $systemPlaceholders): void {
                        $typed->whereRaw('LOWER(TRIM(wp_post_type)) NOT IN ('.$systemPlaceholders.')', $systemTypes)
                            ->whereRaw("LOWER(TRIM(wp_post_type)) NOT LIKE ? ESCAPE ?", ['wp\_%', '\\']);
                    });
            })
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN LOWER(TRIM(COALESCE(content_type, ''))) = 'post' THEN 1 ELSE 0 END) as ct_post")
            ->selectRaw("SUM(CASE WHEN LOWER(TRIM(COALESCE(content_type, ''))) = 'page' THEN 1 ELSE 0 END) as ct_page")
            ->selectRaw("SUM(CASE WHEN LOWER(TRIM(COALESCE(content_type, ''))) = 'product' THEN 1 ELSE 0 END) as ct_product")
            ->selectRaw("SUM(CASE WHEN LOWER(TRIM(COALESCE(content_type, ''))) NOT IN ('post', 'page', 'product') THEN 1 ELSE 0 END) as ct_other")
            ->selectRaw("SUM(CASE WHEN {$wp} THEN 1 ELSE 0 END) as source_present")
            ->selectRaw("SUM(CASE WHEN NOT ({$wp}) THEN 1 ELSE 0 END) as source_na")
            ->selectRaw("SUM(CASE WHEN {$filled('title')} THEN 1 ELSE 0 END) as title_present")
            ->selectRaw("SUM(CASE WHEN NOT ({$filled('title')}) THEN 1 ELSE 0 END) as title_missing")
            ->selectRaw("SUM(CASE WHEN {$filled('slug')} THEN 1 ELSE 0 END) as slug_present")
            ->selectRaw("SUM(CASE WHEN NOT ({$filled('slug')}) AND {$draft} THEN 1 ELSE 0 END) as slug_source_absent")
            ->selectRaw("SUM(CASE WHEN NOT ({$filled('slug')}) AND NOT ({$draft}) THEN 1 ELSE 0 END) as slug_missing")
            ->selectRaw("SUM(CASE WHEN NOT ({$wp}) THEN 1 ELSE 0 END) as permalink_na")
            ->selectRaw("SUM(CASE WHEN {$wp} AND {$filled('wp_permalink')} THEN 1 ELSE 0 END) as permalink_present")
            ->selectRaw("SUM(CASE WHEN {$wp} AND NOT ({$filled('wp_permalink')}) AND {$draft} THEN 1 ELSE 0 END) as permalink_source_absent")
            ->selectRaw("SUM(CASE WHEN {$wp} AND NOT ({$filled('wp_permalink')}) AND NOT ({$draft}) THEN 1 ELSE 0 END) as permalink_missing")
            ->selectRaw("SUM(CASE WHEN {$ctOk} THEN 1 ELSE 0 END) as content_type_present")
            ->selectRaw("SUM(CASE WHEN NOT ({$ctOk}) THEN 1 ELSE 0 END) as content_type_missing")
            ->selectRaw("SUM(CASE WHEN NOT ({$wp}) THEN 1 ELSE 0 END) as wp_post_type_na")
            ->selectRaw("SUM(CASE WHEN {$wp} AND {$filled('wp_post_type')} THEN 1 ELSE 0 END) as wp_post_type_present")
            ->selectRaw("SUM(CASE WHEN {$wp} AND NOT ({$filled('wp_post_type')}) THEN 1 ELSE 0 END) as wp_post_type_missing")
            ->selectRaw("SUM(CASE WHEN {$filled('status')} THEN 1 ELSE 0 END) as status_present")
            ->selectRaw("SUM(CASE WHEN NOT ({$filled('status')}) THEN 1 ELSE 0 END) as status_missing")
            ->first();

        if ($row === null) {
            return $empty;
        }

        $n = static fn (string $key): int => (int) ($row->{$key} ?? 0);

        return [
            'total' => $n('total'),
            'by_content_type' => [
                'post' => $n('ct_post'),
                'page' => $n('ct_page'),
                'product' => $n('ct_product'),
                'other' => $n('ct_other'),
            ],
            'fields' => [
                'source_id' => [
                    self::OUTCOME_PRESENT => $n('source_present'),
                    self::OUTCOME_MISSING => 0,
                    self::OUTCOME_NOT_APPLICABLE => $n('source_na'),
                    self::OUTCOME_SOURCE_ABSENT => 0,
                ],
                'title' => [
                    self::OUTCOME_PRESENT => $n('title_present'),
                    self::OUTCOME_MISSING => $n('title_missing'),
                    self::OUTCOME_NOT_APPLICABLE => 0,
                    self::OUTCOME_SOURCE_ABSENT => 0,
                ],
                'slug' => [
                    self::OUTCOME_PRESENT => $n('slug_present'),
                    self::OUTCOME_MISSING => $n('slug_missing'),
                    self::OUTCOME_NOT_APPLICABLE => 0,
                    self::OUTCOME_SOURCE_ABSENT => $n('slug_source_absent'),
                ],
                'permalink' => [
                    self::OUTCOME_PRESENT => $n('permalink_present'),
                    self::OUTCOME_MISSING => $n('permalink_missing'),
                    self::OUTCOME_NOT_APPLICABLE => $n('permalink_na'),
                    self::OUTCOME_SOURCE_ABSENT => $n('permalink_source_absent'),
                ],
                'content_type' => [
                    self::OUTCOME_PRESENT => $n('content_type_present'),
                    self::OUTCOME_MISSING => $n('content_type_missing'),
                    self::OUTCOME_NOT_APPLICABLE => 0,
                    self::OUTCOME_SOURCE_ABSENT => 0,
                ],
                'wp_post_type' => [
                    self::OUTCOME_PRESENT => $n('wp_post_type_present'),
                    self::OUTCOME_MISSING => $n('wp_post_type_missing'),
                    self::OUTCOME_NOT_APPLICABLE => $n('wp_post_type_na'),
                    self::OUTCOME_SOURCE_ABSENT => 0,
                ],
                'status' => [
                    self::OUTCOME_PRESENT => $n('status_present'),
                    self::OUTCOME_MISSING => $n('status_missing'),
                    self::OUTCOME_NOT_APPLICABLE => 0,
                    self::OUTCOME_SOURCE_ABSENT => 0,
                ],
            ],
        ];
    }

    private function worseSeverity(string $current, string $candidate): string
    {
        $rank = [
            ArticleRequiredDataRegistry::SEVERITY_GREEN => 0,
            ArticleRequiredDataRegistry::SEVERITY_YELLOW => 1,
            ArticleRequiredDataRegistry::SEVERITY_RED => 2,
        ];

        return ($rank[$candidate] ?? 0) > ($rank[$current] ?? 0) ? $candidate : $current;
    }
}
