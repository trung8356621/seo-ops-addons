<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchFoundation\Support\KeywordLinkDetailPanelPresenter;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDetailQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicInternalLinkCounter;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicLinkedArticleCounter;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicListQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordItemPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract: Focus / Linked / Internal count semantics across Keyword + Topic + Map.
 */
final class ArticleLinkCountSemanticsContractTest extends TestCase
{
    public function test_topic_article_counter_is_distinct_focus_not_link_sources(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicLinkedArticleCounter::class))->getFileName(),
        );

        self::assertStringContainsString('DISTINCT Focus Articles', $src);
        self::assertStringContainsString('keyword_meta', $src);
        self::assertStringContainsString('siteMainArticleId', $src);
        self::assertStringNotContainsString('SeoLinkMap::query()', $src);
        self::assertStringContainsString('excludeKeywordIds', $src);
    }

    public function test_topic_internal_link_counter_counts_internal_edges_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicInternalLinkCounter::class))->getFileName(),
        );

        self::assertStringContainsString('SeoLinkMapType::Internal', $src);
        self::assertStringContainsString('SeoLinkMapStatus::Ignored', $src);
        self::assertStringContainsString('sourceArticle', $src);
        self::assertStringContainsString('->count()', $src);
        self::assertStringNotContainsString('distinct()', $src);
    }

    public function test_topic_list_and_detail_separate_article_and_internal_link_counts(): void
    {
        $list = (string) file_get_contents(
            (string) (new ReflectionClass(TopicListQuery::class))->getFileName(),
        );
        $detail = (string) file_get_contents(
            (string) (new ReflectionClass(TopicDetailQuery::class))->getFileName(),
        );

        self::assertStringContainsString('TopicInternalLinkCounter', $list);
        self::assertStringContainsString('TopicInternalLinkCounter', $detail);
        self::assertStringContainsString("'internal_link_count' => \$internalLinkCount", $list);
        self::assertStringContainsString("'internal_link_count' => \$internalLinkCount", $detail);
        self::assertStringNotContainsString("'internal_link_count' => \$articleCount", $list);
        self::assertStringNotContainsString("'internal_link_count' => \$articleCount", $detail);
    }

    public function test_keyword_item_presenter_uses_linked_article_count(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordItemPresenter::class))->getFileName(),
        );

        self::assertStringContainsString('resolveLinkedArticleCount', $src);
        self::assertStringContainsString('linked_article_count', $src);
        self::assertStringContainsString('KeywordLinkDetailPanelPresenter', $src);
        self::assertStringNotContainsString('$keyword->linked_articles_count ?? 0', $src);
    }

    public function test_keyword_detail_panel_exposes_count_contract(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordLinkDetailPanelPresenter::class))->getFileName(),
        );

        self::assertStringContainsString('function counts(', $src);
        self::assertStringContainsString('function linkedArticleCount(', $src);
        self::assertStringContainsString('function internalLinkCount(', $src);
        self::assertStringContainsString('function focusArticleCount(', $src);
        self::assertStringContainsString("'focus_article_count'", $src);
        self::assertStringContainsString("'linked_article_count'", $src);
        self::assertStringContainsString("'internal_link_count'", $src);
        // Focus excluded from linked list; edges stay in buildItems.
        self::assertStringContainsString('$articleId === $focusArticleId', $src);
        self::assertStringContainsString('isset($seen[$articleId])', $src);
    }

    public function test_topic_detail_members_set_linked_article_count_from_presenter(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicDetailQuery::class))->getFileName(),
        );

        self::assertStringContainsString('linked_article_count', $src);
        self::assertStringContainsString('linkedArticleCount($keyword, $siteId)', $src);
        self::assertStringContainsString("count(distinct seo_link_maps.source_article_id)", $src);
    }

    public function test_landscape_article_count_doc_means_distinct_focus(): void
    {
        $dto = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Topic/Dto/KeywordLandscapeTopic.php',
        );
        $landscape = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Topic/KeywordLandscapeReadModel.php',
        );

        self::assertStringContainsString('DISTINCT Focus Articles', $dto);
        self::assertStringContainsString('TopicLinkedArticleCounter', $landscape);
        self::assertStringContainsString('excludedKeywordIds', $landscape);
        self::assertStringContainsString('mcp_excluded', $landscape);
    }
}
