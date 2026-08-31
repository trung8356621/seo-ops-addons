<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\Health;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Support\ArticleRequiredDataRegistry;
use Illuminate\Support\Facades\Schema;

/**
 * Local-only aggregate audit of ArticleRequiredDataRegistry fields (no WP HTTP, no sync).
 */
final class ArticleRequiredDataHealthAuditor
{
    /**
     * @return array{
     *   total: int,
     *   by_content_type: array{post: int, page: int, product: int, other: int},
     *   fields: list<array{
     *     key: string,
     *     label: string,
     *     present: int,
     *     missing: int,
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
    public function audit(int $siteId): array
    {
        $total = (int) SeoArticle::query()->where('site_id', $siteId)->count();
        $byType = $this->countByContentType($siteId);
        $fields = [];
        $maxMissing = 0;
        $worst = ArticleRequiredDataRegistry::SEVERITY_GREEN;

        foreach (ArticleRequiredDataRegistry::all() as $def) {
            $missing = $total === 0 ? 0 : $this->countMissing($siteId, $def);
            $present = max(0, $total - $missing);
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
                'present' => $present,
                'missing' => $missing,
                'total' => $total,
                'severity' => $severity,
                'how_to_check' => $def['how_to_check'],
                'storage' => $def['storage'],
                'technical_key' => $technicalKey,
            ];
        }

        return [
            'total' => $total,
            'by_content_type' => $byType,
            'fields' => $fields,
            'worst_severity' => $worst,
            'max_missing' => $maxMissing,
        ];
    }

    /**
     * @return array{post: int, page: int, product: int, other: int}
     */
    public function countByContentType(int $siteId): array
    {
        $base = SeoArticle::query()->where('site_id', $siteId);
        $post = ArticleContentClassification::scopeNonTerm(
            ArticleContentClassification::scopeContentType(clone $base, ContentType::Post),
        )->count();
        $page = ArticleContentClassification::scopeNonTerm(
            ArticleContentClassification::scopeContentType(clone $base, ContentType::Page),
        )->count();
        $product = ArticleContentClassification::scopeNonTerm(
            ArticleContentClassification::scopeContentType(clone $base, ContentType::Product),
        )->count();
        $total = (clone $base)->count();

        return [
            'post' => $post,
            'page' => $page,
            'product' => $product,
            'other' => max(0, $total - ($post + $page + $product)),
        ];
    }

    /**
     * @param  array{
     *   key: string,
     *   storage: string,
     *   column?: string,
     *   meta_key?: string,
     *   relation?: string
     * }  $def
     */
    private function countMissing(int $siteId, array $def): int
    {
        return match ($def['storage']) {
            'column' => $this->countMissingColumn($siteId, (string) ($def['column'] ?? '')),
            'meta' => $this->countMissingMeta($siteId, (string) ($def['meta_key'] ?? ''), $def['key']),
            'relation' => $this->countMissingSourceId($siteId),
            default => 0,
        };
    }

    private function countMissingColumn(int $siteId, string $column): int
    {
        if ($column === '' || ! Schema::connection('omi_seo_ai')->hasColumn('articles', $column)) {
            return 0;
        }

        return (int) SeoArticle::query()
            ->where('site_id', $siteId)
            ->where(static function ($q) use ($column): void {
                $q->whereNull($column)
                    ->orWhereRaw('TRIM('.$column.') = ?', ['']);
            })
            ->count();
    }

    private function countMissingMeta(int $siteId, string $metaKey, string $fieldKey): int
    {
        if ($metaKey === '' || ! Schema::connection('omi_seo_ai')->hasTable('article_meta')) {
            return (int) SeoArticle::query()->where('site_id', $siteId)->count();
        }

        $allowed = $fieldKey === 'content_type'
            ? array_map(static fn (ContentType $t): string => $t->value, ContentType::cases())
            : [];

        $query = SeoArticle::query()
            ->where('articles.site_id', $siteId)
            ->leftJoin('article_meta as arm_req', static function ($join) use ($metaKey): void {
                $join->on('arm_req.article_id', '=', 'articles.id')
                    ->where('arm_req.meta_key', '=', $metaKey);
            })
            ->where(static function ($q) use ($allowed): void {
                $q->whereNull('arm_req.meta_value')
                    ->orWhereRaw("TRIM(arm_req.meta_value) = ''");
                // Present but invalid (not post|page|product) also counts as structural miss.
                if ($allowed !== []) {
                    $placeholders = implode(',', array_fill(0, count($allowed), '?'));
                    $q->orWhereRaw(
                        'LOWER(TRIM(arm_req.meta_value)) NOT IN ('.$placeholders.')',
                        $allowed,
                    );
                }
            });

        return (int) $query->count('articles.id');
    }

    private function countMissingSourceId(int $siteId): int
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')) {
            return (int) SeoArticle::query()->where('site_id', $siteId)->count();
        }

        return (int) SeoArticle::query()
            ->where('articles.site_id', $siteId)
            ->leftJoin('wordpress_article_links as wal_req', 'wal_req.article_id', '=', 'articles.id')
            ->where(static function ($q): void {
                $q->whereNull('wal_req.wp_post_id')
                    ->orWhere('wal_req.wp_post_id', '<=', 0);
            })
            ->count('articles.id');
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
