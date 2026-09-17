<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
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
        private readonly HideKeywordFromSeoService $hideService,
        private readonly SkipKeywordFromMcpService $mcpSkipService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(
        Keyword $keyword,
        string $context,
        ?int $siteId = null,
        ?array $dnaValues = null,
        string $clusterKey = '',
    ): array {
        unset($dnaValues, $clusterKey);

        $context = $context === self::CONTEXT_CLUSTER ? self::CONTEXT_CLUSTER : self::CONTEXT_DICTIONARY;
        $siteId = $siteId ?? KeywordResource::resolveKeywordSiteId($keyword) ?? SeoAccessControl::globalSiteId();
        $siteId = is_int($siteId) && $siteId > 0 ? $siteId : null;

        $keywordId = (int) $keyword->id;
        $articleCount = (int) ($keyword->linked_articles_count ?? 0);
        $isHidden = $this->hideService->isHidden($keywordId);
        $isMcpSkipped = $this->mcpSkipService->isSkipped($keywordId);
        $groupedTags = $this->groupedTags($keyword);
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
        }

        return [
            'keyword_id' => $keywordId,
            'raw_phrase' => (string) $keyword->phrase,
            'display_phrase' => KeywordPhrasePresentation::present((string) $keyword->phrase),
            'semantic_tags' => [],
            'operational_tags' => $groupedTags['operational'],
            'planning_tags' => $groupedTags['planning'],
            'intent' => '',
            'intent_label' => '',
            'article_count' => $articleCount,
            'article_count_label' => $articleCount > 0
                ? trans_choice('seo-content-ai::filament.keyword.topic_row_article_count', $articleCount, [
                    'count' => number_format($articleCount),
                ])
                : '—',
            'show_cluster' => false,
            'context' => $context,
            'can_edit_phrase' => KeywordResource::canEdit($keyword),
            'can_mutate' => SeoAccessControl::canMutateInSeoPanel()
                && ($siteId === null || SeoAccessControl::canAccessSite($siteId)),
            'is_hidden' => $isHidden,
            'is_mcp_skipped' => $isMcpSkipped,
            'can_hide' => KeywordResource::canMutateKeywordVisibility($keyword) && ! $isHidden,
            'can_restore' => KeywordResource::canMutateKeywordVisibility($keyword) && $isHidden,
            'can_skip_mcp' => KeywordResource::canMutateKeywordVisibility($keyword) && ! $isHidden && ! $isMcpSkipped,
            'can_restore_mcp' => KeywordResource::canMutateKeywordVisibility($keyword) && ! $isHidden && $isMcpSkipped,
            'can_delete' => KeywordResource::canDelete($keyword),
        ];
    }

    /**
     * @return array{operational: list<array{code: string, label: string, badge_class: string}>, planning: list<array{code: string, label: string, badge_class: string}>}
     */
    public function groupedTags(Keyword $keyword): array
    {
        $operational = [];
        $planning = [];

        foreach ($this->tags->displayTags($keyword) as $tag) {
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
}
