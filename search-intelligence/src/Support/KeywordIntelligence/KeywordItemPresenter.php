<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\HideKeywordFromSeoService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\SkipKeywordFromMcpService;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
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
        private readonly KeywordDnaService $dnaService,
        private readonly HideKeywordFromSeoService $hideService,
        private readonly SkipKeywordFromMcpService $mcpSkipService,
        private readonly KeywordClusterQuery $clusters,
    ) {}

    /**
     * @param  list<string>|null  $dnaValues
     * @return array<string, mixed>
     */
    public function present(
        Keyword $keyword,
        string $context,
        ?int $siteId = null,
        ?array $dnaValues = null,
        string $clusterKey = '',
    ): array {
        $context = $context === self::CONTEXT_CLUSTER ? self::CONTEXT_CLUSTER : self::CONTEXT_DICTIONARY;
        $siteId = $siteId ?? KeywordResource::resolveKeywordSiteId($keyword) ?? SeoAccessControl::globalSiteId();
        $siteId = is_int($siteId) && $siteId > 0 ? $siteId : null;

        $keywordId = (int) $keyword->id;
        if ($dnaValues === null) {
            $dnaValues = $this->dnaService->displayValuesForKeywords([$keywordId])[$keywordId] ?? [];
        }

        $classification = $keyword->seoClassification;
        $intent = $classification instanceof SeoKeywordClassification
            ? trim((string) ($classification->seo_intent ?? ''))
            : '';

        $clusterKeyFromRow = $classification instanceof SeoKeywordClassification
            ? trim((string) ($classification->cluster_key ?? ''))
            : '';
        $effectiveClusterKey = $clusterKey !== '' ? $clusterKey : $clusterKeyFromRow;

        $clusterLabel = '—';
        $clusterUrl = null;
        if ($clusterKeyFromRow !== '') {
            $clusterLabel = $this->tags->clusterLabel($keyword);
            if (str_contains($clusterLabel, ' · ')) {
                $clusterLabel = trim(explode(' · ', $clusterLabel, 2)[0]);
            }
            $clusterUrl = app(DomainContextResolver::class)->appendSiteToUrl(
                KeywordResource::getUrl('cluster', ['clusterKey' => $clusterKeyFromRow]),
                $siteId,
            );
        }

        $articleCount = (int) ($keyword->linked_articles_count ?? 0);
        $isHidden = $this->hideService->isHidden($keywordId);
        $isMcpSkipped = $this->mcpSkipService->isSkipped($keywordId);
        $groupedTags = $this->groupedTags($keyword);
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
            'semantic_tags' => $this->semanticTags->forKeyword($keyword, $dnaValues, $siteId),
            'operational_tags' => $groupedTags['operational'],
            'planning_tags' => $groupedTags['planning'],
            'intent' => $intent,
            'intent_label' => $intent !== '' ? KeywordRuleClassifier::intentLabel($intent) : '',
            'article_count' => $articleCount,
            'article_count_label' => $articleCount > 0
                ? trans_choice('seo-content-ai::filament.keyword.topic_row_article_count', $articleCount, [
                    'count' => number_format($articleCount),
                ])
                : '—',
            'cluster_key' => $effectiveClusterKey,
            'cluster_label' => $clusterLabel,
            'cluster_url' => $clusterUrl,
            'show_cluster' => $context === self::CONTEXT_DICTIONARY,
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

        foreach ($this->planningSourceTags($keyword) as $tag) {
            $planning[] = $tag;
        }

        return [
            'operational' => $operational,
            'planning' => $planning,
        ];
    }

    /**
     * @return list<array{code: string, label: string, badge_class: string}>
     */
    private function planningSourceTags(Keyword $keyword): array
    {
        $source = trim((string) ($keyword->seoClassification?->source_kind ?? ''));
        $label = match ($source) {
            KeywordSourceNormalizer::KEYWORD_DISCOVERY => __('seo-content-ai::filament.keyword.keyword_item_tag_mcp_suggest'),
            KeywordSourceNormalizer::AI_GENERATED => __('seo-content-ai::filament.keyword.keyword_item_tag_vocabulary_suggest'),
            KeywordSourceNormalizer::CONTENT_PROJECT => __('seo-content-ai::filament.keyword.keyword_item_tag_planned'),
            default => null,
        };

        if ($label === null) {
            return [];
        }

        return [[
            'code' => 'source:'.$source,
            'label' => $label,
            'badge_class' => 'keyword-item-tag keyword-item-tag--planning',
        ]];
    }
}
