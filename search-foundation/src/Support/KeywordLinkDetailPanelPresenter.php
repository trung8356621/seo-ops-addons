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

final class KeywordLinkDetailPanelPresenter
{
    /**
     * @return list<array<string, mixed>>
     */
    public function buildItems(Keyword $keyword): array
    {
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
            $siteId = (int) ($sourceArticle?->site_id ?? 0);
            $domain = trim((string) ($domainMap[$siteId] ?? ''));
            if ($domain === '' && $siteId > 0) {
                $domain = '#'.$siteId;
            }

            $targetUrl = $this->resolveTargetUrl($map);
            $httpStatus = $map->last_http_status !== null ? (int) $map->last_http_status : null;
            $network = SeoLinkMapNetworkStatusPresenter::present($httpStatus, $map->status);
            $linkType = $map->link_type instanceof SeoLinkMapType ? $map->link_type : SeoLinkMapType::Internal;
            $weakContext = $this->hasWeakContext($map);

            $items[] = [
                'id' => (int) $map->id,
                'domain' => $domain,
                'domain_initials' => $this->resolveDomainInitials($domain),
                'source_title' => trim((string) ($sourceArticle?->title ?? '')) ?: KeywordResource::resolveLinkMapSourceLabel($sourceArticle),
                'source_edit_url' => $sourceArticle instanceof SeoArticle
                    ? ArticleResource::getUrl('edit', ['record' => $sourceArticle->id])
                    : null,
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
    public function buildLinkedSourceArticles(Keyword $keyword): array
    {
        $resolver = app(KeywordLinkTargetResolver::class);
        $maps = $keyword->relationLoaded('linkMaps')
            ? $keyword->linkMaps
            : $keyword->linkMaps()
                ->orderBy('id')
                ->with([
                    'sourceArticle' => static fn ($articleQuery): mixed => $articleQuery->withTrashed()->with('site'),
                ])
                ->get();

        $focusArticleIds = $keyword->relationLoaded('mainArticles')
            ? $keyword->mainArticles->pluck('id')->map(static fn (mixed $id): int => (int) $id)
            : collect($keyword->mainArticleId() !== null ? [$keyword->mainArticleId()] : []);

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

            $articleId = (int) $sourceArticle->id;
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
                $focusArticleIds->contains($articleId),
            );
        }

        if ($keyword->relationLoaded('mainArticles')) {
            foreach ($keyword->mainArticles as $focusArticle) {
                if (! $focusArticle instanceof SeoArticle) {
                    continue;
                }

                $articleId = (int) $focusArticle->id;
                if (isset($seen[$articleId])) {
                    continue;
                }

                $seen[$articleId] = true;
                $title = trim((string) ($focusArticle->title ?? ''))
                    ?: KeywordResource::resolveLinkMapSourceLabel($focusArticle);

                $items[] = $this->presentLinkedSourceArticle(
                    $focusArticle,
                    $articleId,
                    $title,
                    $resolver,
                    true,
                );
            }
        }

        return $items;
    }

    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     wp_url: string|null,
     *     edit_url: string|null,
     *     is_focus: bool,
     *     can_assign_content_project: bool,
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

        return [
            'id' => $articleId,
            'title' => $title,
            'wp_url' => $resolver->resolveArticlePublicUrl($article),
            'edit_url' => ArticleResource::getUrl('edit', ['record' => $articleId]),
            'is_focus' => $isFocus,
            'can_assign_content_project' => $canAssign,
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
}
