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
use Illuminate\Database\Eloquent\Builder;

/**
 * Agent read adapter — reuse Web SEO Audit query (SeoAuditKeywordFlagService).
 * Site-level: requires site_ref context, không cần project_ref.
 */
final class SeoAuditAgentReadService
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

        $base = $this->baseArticleQuery($siteId, $postType);
        $paginator = $this->auditResults->paginateMergedResults(
            $base,
            $selectedRules,
            $filterLow,
            false,
            1,
            $limit,
        );

        $items = [];
        foreach ($paginator->items() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $items[] = [
                'title' => (string) ($row['title'] ?? ''),
                'domain' => (string) ($row['domain'] ?? ''),
                'score' => $row['score'] ?? null,
                'post_type' => $postType,
                'focus_keyword' => (string) ($row['focus_keyword'] ?? ''),
                'reason_labels' => array_values(array_filter(
                    array_map('strval', is_array($row['reason_labels'] ?? null) ? $row['reason_labels'] : []),
                )),
                'has_keyword_flags' => (bool) ($row['has_keyword_flags'] ?? false),
            ];
        }

        return [
            'items' => $items,
            'total' => (int) $paginator->total(),
            'post_type' => $postType,
        ];
    }

    /**
     * @return Builder<SeoArticle>
     */
    private function baseArticleQuery(int $siteId, ?string $postType): Builder
    {
        $query = ArticleContentClassification::scopeNonTerm(
            SeoArticle::query()
                ->countsTowardSeoScore()
                ->where('status', '!=', 'trash')
                ->orderByDesc('updated_at'),
        );

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
