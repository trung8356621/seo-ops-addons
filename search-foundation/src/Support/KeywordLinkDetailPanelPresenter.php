<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Support;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoLinkMapNetworkStatusPresenter;

final class KeywordLinkDetailPanelPresenter
{
    /**
     * @return list<array<string, mixed>>
     */
    public function buildItems(Keyword $keyword, ?int $siteId = null): array
    {
        $siteId = $this->resolveViewSiteId($keyword, $siteId);
        $domainMap = KeywordResource::siteSelectOptions();
        $maps = $keyword->relationLoaded('linkMaps')
            ? $keyword->linkMaps
            : $keyword->linkMaps()
                ->orderBy('id')
                ->with([
                    'sourceArticle' => static fn ($articleQuery): mixed => $articleQuery->withTrashed()->with('site'),
                    'targetArticle',
                ])
                ->get();

        $items = [];

        foreach ($maps as $map) {
            if (! $map instanceof SeoLinkMap || $map->status === SeoLinkMapStatus::Ignored) {
                continue;
            }

            $sourceArticle = $map->sourceArticle;
            $sourceSiteId = (int) ($sourceArticle?->site_id ?? 0);
            if ($siteId > 0 && $sourceSiteId > 0 && $sourceSiteId !== $siteId) {
                continue;
            }

            $domain = trim((string) ($domainMap[$sourceSiteId] ?? ''));
            if ($domain === '' && $sourceSiteId > 0) {
                $domain = '#'.$sourceSiteId;
            }

            $targetUrl = $this->resolveTargetUrl($map);
            $httpStatus = $map->last_http_status !== null ? (int) $map->last_http_status : null;
            $network = SeoLinkMapNetworkStatusPresenter::present($httpStatus, $map->status);
            $linkType = $map->link_type instanceof SeoLinkMapType ? $map->link_type : SeoLinkMapType::Internal;
            $weakContext = $this->hasWeakContext($map);
            $targetArticle = $map->relationLoaded('targetArticle') ? $map->targetArticle : null;

            // Cross-site targets must not appear as internal resolved articles for this keyword site.
            if (
                $targetArticle instanceof SeoArticle
                && $siteId > 0
                && (int) ($targetArticle->site_id ?? 0) !== $siteId
            ) {
                if ($linkType === SeoLinkMapType::Internal) {
                    $linkType = SeoLinkMapType::External;
                }
                $targetArticle = null;
            }

            $resolvedArticle = $targetArticle instanceof SeoArticle ? $targetArticle : null;
            $resolvedArticleId = $resolvedArticle instanceof SeoArticle ? (int) $resolvedArticle->id : 0;
            $canAddResolved = $resolvedArticle instanceof SeoArticle
                && SeoAccessControl::canMutateInSeoPanel()
                && ! ArticleResource::articleIsContentArchived($resolvedArticle);

            $items[] = [
                'id' => (int) $map->id,
                'domain' => $domain,
                'domain_initials' => $this->resolveDomainInitials($domain),
                'source_title' => trim((string) ($sourceArticle?->title ?? '')) ?: KeywordResource::resolveLinkMapSourceLabel($sourceArticle),
                'source_edit_url' => $sourceArticle instanceof SeoArticle
                    ? ArticleResource::getUrl('edit', ['record' => $sourceArticle->id])
                    : null,
                'source_article_id' => $sourceArticle instanceof SeoArticle ? (int) $sourceArticle->id : null,
                'source_site_id' => $sourceArticle instanceof SeoArticle ? (int) ($sourceArticle->site_id ?? 0) : 0,
                'target_article_id' => $targetArticle instanceof SeoArticle ? (int) $targetArticle->id : null,
                'target_site_id' => $targetArticle instanceof SeoArticle ? (int) ($targetArticle->site_id ?? 0) : 0,
                'resolved_article_id' => $resolvedArticleId > 0 ? $resolvedArticleId : null,
                'resolved_site_id' => $resolvedArticle instanceof SeoArticle ? (int) ($resolvedArticle->site_id ?? 0) : 0,
                'resolved_edit_url' => $resolvedArticleId > 0
                    ? ArticleResource::getUrl('edit', ['record' => $resolvedArticleId])
                    : null,
                'can_add_to_draft' => $canAddResolved && $resolvedArticleId > 0,
                'target_url' => $targetUrl,
                'target_label' => KeywordResource::formatLinkShorthand($targetUrl),
                'anchor_text' => (string) $map->anchor_text,
                'context_before' => trim((string) ($map->context_before ?? '')),
                'context_after' => trim((string) ($map->context_after ?? '')),
                'link_type' => $linkType->value,
                'link_type_label' => self::linkTypeLabel($linkType),
                'link_type_badge_class' => self::linkTypeBadgeClass($linkType),
                'network' => $network,
                'is_broken_network' => SeoLinkMapNetworkStatusPresenter::isBrokenNetwork($httpStatus, $map->status),
                'weak_context' => $weakContext,
                'can_assign_content_project' => KeywordResource::canAssignKeywordToContentProject($keyword),
            ];
        }

        return $items;
    }

    /**
     * Canonical Focus Article for the current view site.
     *
     * @return array{
     *     id: int,
     *     title: string,
     *     wp_url: string|null,
     *     edit_url: string|null,
     *     site_id: int,
     *     is_focus: bool,
     *     can_assign_content_project: bool,
     *     in_draft: bool,
     *     content_project_url: string|null
     * }|null
     */
    public function buildFocusArticle(Keyword $keyword, ?int $siteId = null): ?array
    {
        $siteId = $this->resolveViewSiteId($keyword, $siteId);
        if ($siteId <= 0) {
            return null;
        }

        $focusArticle = $keyword->mainArticlesForSite($siteId)->first();
        if (! $focusArticle instanceof SeoArticle) {
            return null;
        }

        $articleId = (int) $focusArticle->id;
        $title = trim((string) ($focusArticle->title ?? ''))
            ?: KeywordResource::resolveLinkMapSourceLabel($focusArticle);

        return $this->presentLinkedSourceArticle(
            $focusArticle,
            $articleId,
            $title,
            app(KeywordLinkTargetResolver::class),
            true,
        );
    }

    /**
     * Presentation counts — SSOT for Keyword Detail sidebar mini-stats and row labels.
     *
     * - focus_article_count: 0|1 for current site Focus Article
     * - linked_article_count: DISTINCT non-focus source articles (valid linkMaps)
     * - internal_link_count: actual internal edges rendered by buildItems()
     *
     * @return array{
     *     focus_article_count: int,
     *     linked_article_count: int,
     *     internal_link_count: int
     * }
     */
    public function counts(Keyword $keyword, ?int $siteId = null): array
    {
        $siteId = $this->resolveViewSiteId($keyword, $siteId);
        $focus = $this->buildFocusArticle($keyword, $siteId > 0 ? $siteId : null);
        $linked = $this->buildLinkedSourceArticles($keyword, $siteId > 0 ? $siteId : null);
        $items = $this->buildItems($keyword, $siteId > 0 ? $siteId : null);

        return [
            'focus_article_count' => $focus !== null ? 1 : 0,
            'linked_article_count' => count($linked),
            'internal_link_count' => count(array_filter(
                $items,
                static fn (array $item): bool => ($item['link_type'] ?? '') === SeoLinkMapType::Internal->value,
            )),
        ];
    }

    public function focusArticleCount(Keyword $keyword, ?int $siteId = null): int
    {
        return $this->buildFocusArticle($keyword, $siteId) !== null ? 1 : 0;
    }

    public function linkedArticleCount(Keyword $keyword, ?int $siteId = null): int
    {
        return count($this->buildLinkedSourceArticles($keyword, $siteId));
    }

    public function internalLinkCount(Keyword $keyword, ?int $siteId = null): int
    {
        $items = $this->buildItems($keyword, $siteId);

        return count(array_filter(
            $items,
            static fn (array $item): bool => ($item['link_type'] ?? '') === SeoLinkMapType::Internal->value,
        ));
    }

    /**
     * Source articles from valid linkMaps only (excludes Focus Article to avoid duplicate display).
     * Distinct by source_article_id — multiple edges from the same source count as one article.
     *
     * @return list<array{
     *     id: int,
     *     title: string,
     *     wp_url: string|null,
     *     edit_url: string|null,
     *     is_focus: bool,
     *     can_assign_content_project: bool,
     *     content_project_url: string|null
     * }>
     */
    public function buildLinkedSourceArticles(Keyword $keyword, ?int $siteId = null): array
    {
        $siteId = $this->resolveViewSiteId($keyword, $siteId);
        $resolver = app(KeywordLinkTargetResolver::class);
        $maps = $keyword->relationLoaded('linkMaps')
            ? $keyword->linkMaps
            : $keyword->linkMaps()
                ->orderBy('id')
                ->with([
                    'sourceArticle' => static fn ($articleQuery): mixed => $articleQuery->withTrashed()->with('site'),
                ])
                ->get();

        $focusArticleId = 0;
        if ($siteId > 0) {
            $focusArticle = $keyword->mainArticlesForSite($siteId)->first();
            $focusArticleId = $focusArticle instanceof SeoArticle ? (int) $focusArticle->id : 0;
        }

        $items = [];
        $seen = [];

        foreach ($maps as $map) {
            if (! $map instanceof SeoLinkMap || $map->status === SeoLinkMapStatus::Ignored) {
                continue;
            }

            $sourceArticle = $map->sourceArticle;
            if (! $sourceArticle instanceof SeoArticle) {
                continue;
            }

            if ($siteId > 0 && (int) ($sourceArticle->site_id ?? 0) !== $siteId) {
                continue;
            }

            $articleId = (int) $sourceArticle->id;
            if ($focusArticleId > 0 && $articleId === $focusArticleId) {
                continue;
            }

            if (isset($seen[$articleId])) {
                continue;
            }

            $seen[$articleId] = true;
            $title = trim((string) ($sourceArticle->title ?? ''))
                ?: KeywordResource::resolveLinkMapSourceLabel($sourceArticle);

            $items[] = $this->presentLinkedSourceArticle(
                $sourceArticle,
                $articleId,
                $title,
                $resolver,
                false,
            );
        }

        return $items;
    }

    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     wp_url: string|null,
     *     edit_url: string|null,
     *     site_id: int,
     *     is_focus: bool,
     *     can_assign_content_project: bool,
     *     in_draft: bool,
     *     content_project_url: string|null
     * }
     */
    private function presentLinkedSourceArticle(
        SeoArticle $article,
        int $articleId,
        string $title,
        KeywordLinkTargetResolver $resolver,
        bool $isFocus,
    ): array {
        $canAssign = $this->canAssignLinkedArticleToContentProject($article);
        $inProject = ArticleResource::articleIsInContentProject($article);

        return [
            'id' => $articleId,
            'title' => $title,
            'wp_url' => $resolver->resolveArticlePublicUrl($article),
            'edit_url' => ArticleResource::getUrl('edit', ['record' => $articleId]),
            'site_id' => (int) ($article->site_id ?? 0),
            'is_focus' => $isFocus,
            'can_assign_content_project' => $canAssign || $inProject,
            'in_draft' => $inProject,
            'content_project_url' => $canAssign ? null : ArticleResource::articleContentProjectUrl($article),
        ];
    }

    private function canAssignLinkedArticleToContentProject(SeoArticle $article): bool
    {
        return SeoAccessControl::canMutateInSeoPanel()
            && ! ArticleResource::articleIsInContentProject($article)
            && ! ArticleResource::articleIsContentArchived($article);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{all: int, broken: int, weak_context: int}
     */
    public static function tabCounts(array $items): array
    {
        return [
            'all' => count($items),
            'broken' => count(array_filter(
                $items,
                static fn (array $item): bool => (bool) ($item['is_broken_network'] ?? false),
            )),
            'weak_context' => count(array_filter(
                $items,
                static fn (array $item): bool => (bool) ($item['weak_context'] ?? false),
            )),
        ];
    }

    private static function linkTypeLabel(SeoLinkMapType $type): string
    {
        return match ($type) {
            SeoLinkMapType::Internal => __('seo-content-ai::filament.keyword.link_type_internal'),
            SeoLinkMapType::External => __('seo-content-ai::filament.keyword.link_type_external'),
            SeoLinkMapType::WikiTrust => __('seo-content-ai::filament.keyword.link_type_wiki_trust'),
        };
    }

    private static function linkTypeBadgeClass(SeoLinkMapType $type): string
    {
        return match ($type) {
            SeoLinkMapType::Internal => 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300 px-2 py-0.5 rounded text-xs font-medium',
            SeoLinkMapType::External => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300 px-2 py-0.5 rounded text-xs font-medium',
            SeoLinkMapType::WikiTrust => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300 px-2 py-0.5 rounded text-xs font-medium',
        };
    }

    private function hasWeakContext(SeoLinkMap $map): bool
    {
        return mb_strlen(trim((string) ($map->context_before ?? ''))) < 3
            || mb_strlen(trim((string) ($map->context_after ?? ''))) < 3;
    }

    private function resolveTargetUrl(SeoLinkMap $map): string
    {
        $external = trim((string) ($map->target_external_url ?? ''));
        if ($external !== '') {
            return $external;
        }

        $targetArticle = $map->targetArticle;
        if (! $targetArticle instanceof SeoArticle) {
            return '';
        }

        return trim((string) (app(KeywordLinkTargetResolver::class)->resolveArticlePublicUrl($targetArticle) ?? ''));
    }

    private function resolveDomainInitials(string $domain): string
    {
        $domain = preg_replace('#^https?://#', '', strtolower(trim($domain))) ?? '';
        $domain = ltrim(str_replace('www.', '', $domain), '/');

        if ($domain === '') {
            return '?';
        }

        $parts = array_values(array_filter(explode('.', $domain)));

        return strtoupper(substr($parts[0] ?? $domain, 0, 2));
    }

    private function resolveViewSiteId(Keyword $keyword, ?int $siteId): int
    {
        if ($siteId !== null && $siteId > 0) {
            return $siteId;
        }

        return (int) (KeywordResource::resolveKeywordSiteId($keyword) ?? 0);
    }
}
