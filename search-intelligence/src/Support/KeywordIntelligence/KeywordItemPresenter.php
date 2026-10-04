<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Support\KeywordLinkDetailPanelPresenter;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\HideKeywordFromSeoService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\SkipKeywordFromMcpService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * View-model builder for canonical Keyword Item UI (presentation only).
 */
final class KeywordItemPresenter
{
    public const CONTEXT_DICTIONARY = 'dictionary';

    public const CONTEXT_CLUSTER = 'cluster';

    public function __construct(
        private readonly KeywordTagResolver $tags,
        private readonly KeywordSemanticTagPresenter $semanticTags,
        private readonly HideKeywordFromSeoService $hideService,
        private readonly SkipKeywordFromMcpService $mcpSkipService,
    ) {}

    /**
     * @param  list<string>|null  $dnaValues  Explicit Topic DNA values (Topic detail only). Null/empty → no DNA badges.
     * @return array<string, mixed>
     */
    public function present(
        Keyword $keyword,
        string $context,
        ?int $siteId = null,
        ?array $dnaValues = null,
        string $clusterKey = '',
    ): array {
        unset($clusterKey);

        $context = $context === self::CONTEXT_CLUSTER ? self::CONTEXT_CLUSTER : self::CONTEXT_DICTIONARY;
        $siteId = $siteId ?? KeywordResource::resolveKeywordSiteId($keyword) ?? SeoAccessControl::globalSiteId();
        $siteId = is_int($siteId) && $siteId > 0 ? $siteId : null;

        $keywordId = (int) $keyword->id;
        $isDictionary = $context === self::CONTEXT_DICTIONARY;
        $panel = $isDictionary ? null : app(KeywordLinkDetailPanelPresenter::class);
        $focusArticleCount = $this->resolveFocusArticleCount($keyword, $siteId, $panel, $isDictionary);
        $linkedArticleCount = $this->resolveLinkedArticleCount($keyword, $siteId, $panel, $isDictionary);

        $focusArticleCountLabel = __('seo-content-ai::filament.keyword.keyword_row_focus_count', [
            'count' => number_format($focusArticleCount),
        ]);
        $linkedArticleCountLabel = __('seo-content-ai::filament.keyword.keyword_row_linked_count', [
            'count' => number_format($linkedArticleCount),
        ]);

        $attributes = $keyword->getAttributes();
        $isHidden = $isDictionary
            ? (bool) ($attributes['seo_hidden'] ?? false)
            : $this->hideService->isHidden($keywordId);
        $isMcpSkipped = $isDictionary
            ? (bool) ($attributes['mcp_excluded'] ?? false)
            : $this->mcpSkipService->isSkipped($keywordId);
        $groupedTags = $isDictionary
            ? $this->groupResolvedTags($this->tags->resolve([
                'seo_hidden' => $isHidden,
                'internal_link_count' => (int) ($attributes['has_site_links'] ?? false),
                'manual_error' => $keyword->isManualError(),
            ]))
            : $this->groupedTags($keyword);
        if ($isHidden) {
            array_unshift($groupedTags['planning'], [
                'code' => 'seo_hidden',
                'label' => __('seo-content-ai::filament.keyword.keyword_item_tag_seo_excluded'),
                'badge_class' => 'keyword-item-tag keyword-item-tag--planning',
            ]);
        }
        if ($isMcpSkipped) {
            array_unshift($groupedTags['planning'], [
                'code' => 'mcp_excluded',
                'label' => __('seo-content-ai::filament.keyword.keyword_item_tag_mcp_skipped'),
                'badge_class' => 'keyword-item-tag keyword-item-tag--planning',
            ]);
        } elseif ($context === self::CONTEXT_CLUSTER) {
            // Topic member rows: always surface MCP eligibility for quarantine clarity.
            array_unshift($groupedTags['planning'], [
                'code' => 'mcp_included',
                'label' => __('seo-content-ai::filament.keyword.keyword_item_tag_mcp_included'),
                'badge_class' => 'keyword-item-tag keyword-item-tag--planning',
            ]);
        }

        $semanticTags = $this->semanticTags->forKeyword(
            $keyword,
            is_array($dnaValues) ? $dnaValues : [],
            $siteId,
        );

        return [
            'keyword_id' => $keywordId,
            'raw_phrase' => (string) $keyword->phrase,
            'display_phrase' => KeywordPhrasePresentation::present((string) $keyword->phrase),
            'semantic_tags' => $semanticTags,
            'operational_tags' => $groupedTags['operational'],
            'planning_tags' => $groupedTags['planning'],
            'intent' => '',
            'intent_label' => '',
            'focus_article_count' => $focusArticleCount,
            'focus_article_count_label' => $focusArticleCountLabel,
            'linked_article_count' => $linkedArticleCount,
            'linked_article_count_label' => $linkedArticleCountLabel,
            // Always show explicit "N focus · M linked" for SEO inspection (incl. zeros).
            'show_article_meta' => true,
            'show_cluster' => false,
            'context' => $context,
            'can_edit_phrase' => $isDictionary
                ? KeywordResource::canEditFromListState($keyword)
                : KeywordResource::canEdit($keyword),
            'can_mutate' => SeoAccessControl::canMutateInSeoPanel()
                && ($siteId === null || SeoAccessControl::canAccessSite($siteId)),
            'is_hidden' => $isHidden,
            'is_mcp_skipped' => $isMcpSkipped,
            'can_hide' => ($isDictionary ? KeywordResource::canMutateKeywordVisibilityFromListState($keyword) : KeywordResource::canMutateKeywordVisibility($keyword)) && ! $isHidden,
            'can_restore' => ($isDictionary ? KeywordResource::canMutateKeywordVisibilityFromListState($keyword) : KeywordResource::canMutateKeywordVisibility($keyword)) && $isHidden,
            'can_skip_mcp' => ($isDictionary ? KeywordResource::canMutateKeywordVisibilityFromListState($keyword) : KeywordResource::canMutateKeywordVisibility($keyword)) && ! $isHidden && ! $isMcpSkipped,
            'can_restore_mcp' => ($isDictionary ? KeywordResource::canMutateKeywordVisibilityFromListState($keyword) : KeywordResource::canMutateKeywordVisibility($keyword)) && ! $isHidden && $isMcpSkipped,
            'can_delete' => $isDictionary
                ? KeywordResource::canDeleteFromListState($keyword)
                : KeywordResource::canDelete($keyword),
        ];
    }

    /**
     * @return array{operational: list<array{code: string, label: string, badge_class: string}>, planning: list<array{code: string, label: string, badge_class: string}>}
     */
    public function groupedTags(Keyword $keyword): array
    {
        return $this->groupResolvedTags($this->tags->displayTags($keyword));
    }

    /**
     * @param  list<string>|list<array{code: string, label: string, badge_class: string}>  $tags
     * @return array{operational: list<array{code: string, label: string, badge_class: string}>, planning: list<array{code: string, label: string, badge_class: string}>}
     */
    private function groupResolvedTags(array $tags): array
    {
        $operational = [];
        $planning = [];

        foreach ($tags as $tag) {
            if (is_string($tag)) {
                if (! KeywordTag::isKnown($tag)) {
                    continue;
                }
                $tag = [
                    'code' => $tag,
                    'label' => KeywordTag::label($tag),
                    'badge_class' => KeywordTag::badgeClass($tag),
                ];
            }
            $code = (string) ($tag['code'] ?? '');
            if ($code === KeywordTag::SEO_EXCLUDED) {
                $planning[] = [
                    ...$tag,
                    'badge_class' => 'keyword-item-tag keyword-item-tag--planning',
                ];

                continue;
            }

            $operational[] = [
                ...$tag,
                'badge_class' => trim(((string) ($tag['badge_class'] ?? '')).' keyword-item-tag keyword-item-tag--system'),
            ];
        }

        return [
            'operational' => $operational,
            'planning' => $planning,
        ];
    }

    /**
     * Canonical Focus Article count for current site (normally 0|1).
     */
    private function resolveFocusArticleCount(
        Keyword $keyword,
        ?int $siteId,
        ?KeywordLinkDetailPanelPresenter $panel,
        bool $listOnly = false,
    ): int {
        $attributes = $keyword->getAttributes();
        if (array_key_exists('focus_article_count', $attributes) && $attributes['focus_article_count'] !== null) {
            return max(0, (int) $attributes['focus_article_count']);
        }

        return $listOnly || $panel === null ? 0 : $panel->focusArticleCount($keyword, $siteId);
    }

    /**
     * Distinct non-focus source articles for the Keyword row label.
     * Prefers precomputed attribute; otherwise presenter SSOT (excludes Focus).
     */
    private function resolveLinkedArticleCount(
        Keyword $keyword,
        ?int $siteId,
        ?KeywordLinkDetailPanelPresenter $panel,
        bool $listOnly = false,
    ): int {
        $attributes = $keyword->getAttributes();
        if (array_key_exists('linked_article_count', $attributes) && $attributes['linked_article_count'] !== null) {
            return max(0, (int) $attributes['linked_article_count']);
        }

        return $listOnly || $panel === null ? 0 : $panel->linkedArticleCount($keyword, $siteId);
    }
}
