<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordItemPresenter;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicTopicalShareCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Topic UI polish: Topical Share read-model + Dictionary-style member rows.
 */
final class TopicUiPolishTopicalShareAndRowsTest extends TestCase
{
    public function test_topical_share_uses_site_scoped_article_counts(): void
    {
        $calc = new TopicTopicalShareCalculator;
        $shares = $calc->percentages([
            1 => 18,
            2 => 7,
            3 => 75,
        ]);

        self::assertEqualsWithDelta(18.0, $shares[1], 0.01);
        self::assertEqualsWithDelta(7.0, $shares[2], 0.01);
        self::assertEqualsWithDelta(75.0, $shares[3], 0.01);
        self::assertEqualsWithDelta(100.0, array_sum($shares), 0.01);
    }

    public function test_topical_share_zero_denominator_is_zero(): void
    {
        $shares = (new TopicTopicalShareCalculator)->percentages([10 => 0, 11 => 0]);
        self::assertSame(0.0, $shares[10]);
        self::assertSame(0.0, $shares[11]);
    }

    public function test_topical_share_one_site_denominator_only(): void
    {
        // Caller must pass one site's map only — calculator never mixes sites.
        $siteA = (new TopicTopicalShareCalculator)->percentages([1 => 50, 2 => 50]);
        $siteB = (new TopicTopicalShareCalculator)->percentages([1 => 10]);
        self::assertEqualsWithDelta(50.0, $siteA[1], 0.01);
        self::assertEqualsWithDelta(100.0, $siteB[1], 0.01);
        // Same topic_id on another site map does not dilute site A.
        self::assertEqualsWithDelta(50.0, $siteA[1], 0.01);
    }

    public function test_format_percent_matches_ui_examples(): void
    {
        self::assertSame('18%', TopicTopicalShareCalculator::formatPercent(18.0));
        self::assertSame('7.4%', TopicTopicalShareCalculator::formatPercent(7.4));
        self::assertSame('0%', TopicTopicalShareCalculator::formatPercent(0.0));
    }

    public function test_list_query_exposes_numeric_topical_share_and_sort(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicListQuery.php');
        self::assertStringContainsString('TopicTopicalShareCalculator', $src);
        self::assertStringContainsString("'topical_share'", $src);
        self::assertStringContainsString('(float)', $src);
        self::assertStringContainsString('topical_share_desc', $src);
        self::assertStringContainsString('topical_share_asc', $src);
        self::assertStringNotContainsString("'topical_share' => null", $src);
        self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src);
        self::assertStringNotContainsString('SiteMcpClusterTopicalProfileBuilder', $src);
    }

    public function test_index_blade_no_longer_hardcodes_share_dash(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php');
        self::assertStringContainsString('cluster-index-row__share', $blade);
        self::assertStringContainsString('formatPercent', $blade);
        self::assertStringContainsString('topical_share', $blade);
        self::assertStringNotContainsString('<span>—</span>', $blade);
        self::assertStringContainsString('topic_sort_topical_share_desc', $blade);
    }

    public function test_detail_member_rows_use_dictionary_table_cell(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php');
        self::assertStringContainsString('keyword-item-table-cell', $blade);
        self::assertStringContainsString('topic-keyword-member-table', $blade);
        self::assertStringContainsString('phrase_short', $blade);
        self::assertStringContainsString('dnaValues', $blade);
        self::assertStringContainsString('cluster-detail-dna-panel', $blade);
        self::assertStringNotContainsString('keyword-item-list space-y-2', $blade);
    }

    public function test_cluster_context_always_shows_explicit_focus_and_linked_counts(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Support/KeywordIntelligence/KeywordItemPresenter.php');
        self::assertStringContainsString('CONTEXT_CLUSTER', $src);
        self::assertStringContainsString('focus_article_count', $src);
        self::assertStringContainsString('linked_article_count', $src);
        self::assertStringContainsString("'show_article_meta' => true", $src);
        self::assertStringNotContainsString("'article_count' =>", $src);

        $blade = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/keyword-item.blade.php');
        self::assertStringContainsString('show_article_meta', $blade);
        self::assertStringContainsString('focus_article_count_label', $blade);
        self::assertStringContainsString('linked_article_count_label', $blade);
        self::assertStringContainsString('openKeywordDetail', $blade);
        self::assertStringContainsString('openKeywordLinkedArticles', $blade);
        self::assertStringContainsString('keyword-item__counts-sep', $blade);
        self::assertStringContainsString('semantic_tags', $blade);
        self::assertStringContainsString('keyword-item__semantic', $blade);
    }

    public function test_dictionary_table_column_unchanged(): void
    {
        $col = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/tables/columns/keyword-item.blade.php');
        self::assertStringContainsString('CONTEXT_DICTIONARY', $col);
        self::assertStringNotContainsString('CONTEXT_CLUSTER', $col);

        $resource = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource.php');
        self::assertStringContainsString("ViewColumn::make('keyword_item')", $resource);
        self::assertStringContainsString('keyword-item-table-cell', $resource);
    }

    public function test_no_retired_topical_share_runtime(): void
    {
        $files = [
            dirname(__DIR__, 3).'/src/Services/Topic/TopicTopicalShareCalculator.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicListQuery.php',
            dirname(__DIR__, 3).'/src/Support/KeywordIntelligence/KeywordItemPresenter.php',
            dirname(__DIR__, 3).'/src/Support/KeywordIntelligence/KeywordSemanticTagPresenter.php',
        ];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src, basename($file));
            self::assertStringNotContainsString('SiteMcpClusterTopicalProfileBuilder', $src, basename($file));
            self::assertStringNotContainsString('KeywordClusterQuery', $src, basename($file));
            self::assertStringNotContainsString('seo_topic_cluster_meta', $src, basename($file));
            self::assertStringNotContainsString('KeywordDnaService', $src, basename($file));
        }
        self::assertSame(KeywordItemPresenter::CONTEXT_CLUSTER, 'cluster');
    }
}
