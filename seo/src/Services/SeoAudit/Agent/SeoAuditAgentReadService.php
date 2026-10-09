<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SeoAudit\Agent;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\Seo\Services\SeoAuditKeywordFlagService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Agent read adapter — reuse Web SEO Audit query (SeoAuditKeywordFlagService).
 * Site-level: requires site_ref context, không cần project_ref.
 */
class SeoAuditAgentReadService
{
    public function __construct(
        private readonly SeoAuditKeywordFlagService $auditResults,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{items: list<array<string, mixed>>, total: int, post_type: string|null}
     */
    public function listArticles(AgentExecutionContext $context, array $input = []): array
    {
        $siteId = (int) ($context->resolvedSiteId ?? 0);
        $postType = $this->normalizePostType($input['post_type'] ?? $input['postType'] ?? null);
        $limit = max(1, min(100, (int) ($input['limit'] ?? 50)));
        $selectedRules = $this->normalizeStringList($input['rules'] ?? $input['rule_keys'] ?? []);
        $filterLow = (bool) ($input['low_score'] ?? $input['filter_low_seo_score'] ?? false);
        $publishedOnly = (bool) ($input['published_only'] ?? false);
        $sortScoreAsc = (bool) ($input['sort_score_asc'] ?? false);

        $base = $this->baseArticleQuery($siteId, $postType, $publishedOnly);
        $paginator = $this->auditResults->paginateMergedResults(
            $base,
            $selectedRules,
            $filterLow,
            false,
            1,
            $limit,
            $sortScoreAsc ? 'score' : null,
            'asc',
        );

        $items = [];
        foreach ($paginator->items() as $row) {
            if (! is_array($row) || ! $this->keepRetrievedArticle($row, $filterLow, $selectedRules)) {
                continue;
            }
            $items[] = $this->projectRetrievedArticle($row, $postType);
        }

        return [
            'items' => $items,
            'total' => (int) $paginator->total(),
            'post_type' => $postType,
        ];
    }

    /**
     * Compact single Article audit detail scoped strictly to the working site.
     *
     * @return array<string, mixed>|null
     */
    public function getArticle(AgentExecutionContext $context, int $articleId): ?array
    {
        $siteId = (int) ($context->resolvedSiteId ?? 0);
        if ($siteId <= 0 || $articleId <= 0) {
            return null;
        }

        $article = SeoArticle::query()
            ->where('id', $articleId)
            ->where('site_id', $siteId)
            ->where('status', '!=', 'trash')
            ->with([
                'site:id,domain',
                'seoProfile:article_id,seo_score,focus_keyword,internal_link_count,external_link_count',
                'articleMetas' => static function ($relation): void {
                    $relation->whereIn('meta_key', [
                        \Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
                        'seo_focus_keyword',
                        'wp_permalink',
                        'meta_description',
                    ]);
                },
                'linkMaps.keyword.reviewReason',
            ])
            ->first();

        if (! $article instanceof SeoArticle) {
            return null;
        }

        $audit = $this->auditResults->auditArticle($article);
        $profile = $article->seoProfile;

        $projected = $this->projectRetrievedArticle($audit, null);

        return [
            'article_ref' => 'article:'.$article->id,
            'title' => (string) ($article->title ?? ''),
            'slug' => $article->slug,
            'status' => (string) $article->status,
            'focus_keyword' => $projected['focus_keyword'] !== '' ? $projected['focus_keyword'] : ($profile?->focus_keyword ?: null),
            'seo_score' => $projected['seo_score'],
            'system_point' => $projected['system_point'],
            'quality_score' => $projected['quality_score'],
            'rankable' => $projected['rankable'],
            'has_focus_keyword' => $projected['has_focus_keyword'],
            'reason_labels' => $projected['reason_labels'],
            'has_keyword_flags' => (bool) ($audit['has_keyword_flags'] ?? false),
            'internal_link_count' => $profile?->internal_link_count !== null ? (int) $profile->internal_link_count : null,
            'external_link_count' => $profile?->external_link_count !== null ? (int) $profile->external_link_count : null,
            'created_at' => $article->created_at?->toIso8601String(),
            'updated_at' => $article->updated_at?->toIso8601String(),
        ];
    }


    /**
     * @return Builder<SeoArticle>
     */
    private function baseArticleQuery(int $siteId, ?string $postType, bool $publishedOnly = false): Builder
    {
        $query = ArticleContentClassification::scopeNonTerm(
            SeoArticle::query()
                ->countsTowardSeoScore()
                ->where('status', '!=', 'trash')
                ->orderByDesc('updated_at'),
        );
        if ($publishedOnly) {
            // WordPress sync stores publish; the editor also uses published.
            $query->whereIn('status', ['publish', 'published']);
        }

        ArticleResource::applySeoAuditCandidateScope($query);

        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        } elseif (SeoAccessControl::shouldScopeToAccountOwner()) {
            $siteIds = SeoAccessControl::accessibleSiteIds();
            if ($siteIds !== []) {
                $query->whereIn('site_id', $siteIds);
            }
        }

        if ($postType !== null && $postType !== '') {
            $mapped = match ($postType) {
                'article' => ContentType::Post,
                default => ContentType::tryFromString($postType),
            };
            if ($mapped !== null) {
                ArticleContentClassification::scopeContentType($query, $mapped);
            }
        }

        return $query;
    }

    /**
     * Ordinary low-score optimization uses the SEO layer rankable flag.
     * An explicit missing-keyword rule still returns those articles.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $selectedRules
     */
    private function keepRetrievedArticle(array $row, bool $filterLow, array $selectedRules): bool
    {
        if (! $filterLow || in_array(SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD, $selectedRules, true)) {
            return true;
        }

        return ($row['rankable'] ?? null) === true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function projectRetrievedArticle(array $row, ?string $postType): array
    {
        $rankable = ($row['rankable'] ?? null) === true;
        $measured = $row['quality_score'] ?? null;
        if (! is_numeric($measured) && $rankable && is_numeric($row['score'] ?? null)) {
            $measured = $row['score'];
        }
        $quality = $rankable && is_numeric($measured) ? (int) $measured : null;
        $systemPoint = is_numeric($row['system_point'] ?? null) ? (int) $row['system_point'] : null;
        $id = (int) ($row['id'] ?? 0);
        $articleRef = trim((string) ($row['article_ref'] ?? ''));
        if (preg_match('/^article:\d+$/', $articleRef) !== 1) {
            $articleRef = 'article:'.$id;
        }

        return [
            'article_ref' => $articleRef,
            'title' => (string) ($row['title'] ?? ''),
            'slug' => $row['slug'] ?? null,
            'status' => (string) ($row['status'] ?? 'publish'),
            'domain' => (string) ($row['domain'] ?? ''),
            'score' => $quality,
            'seo_score' => $quality,
            'system_point' => $systemPoint,
            'quality_score' => $quality,
            'rankable' => $rankable,
            'post_type' => $postType,
            'focus_keyword' => (string) ($row['focus_keyword'] ?? ''),
            'has_focus_keyword' => array_key_exists('has_focus_keyword', $row) ? (bool) $row['has_focus_keyword'] : null,
            'reason_labels' => array_values(array_filter(
                array_map('strval', is_array($row['reason_labels'] ?? null) ? $row['reason_labels'] : []),
            )),
            'has_keyword_flags' => (bool) ($row['has_keyword_flags'] ?? false),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function normalizePostType(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);
        if ($value === '' || strcasecmp($value, 'all') === 0) {
            return null;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            $raw,
        ), static fn (string $v): bool => $v !== ''));
    }
}
