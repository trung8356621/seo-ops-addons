<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDetailQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordItemPresenter;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSemanticTagPresenter;
use PHPUnit\Framework\TestCase;

/**
 * DNA member-card presentation: explicit dnaValues only, no retired cluster/DNA services.
 */
final class TopicKeywordDnaPresentationTest extends TestCase
{
    public function test_semantic_presenter_maps_dna_values_to_badges(): void
    {
        $presenter = new KeywordSemanticTagPresenter;
        $keyword = new Keyword;
        $tags = $presenter->forKeyword($keyword, ['Balô da', 'túi xách'], 7);

        self::assertCount(2, $tags);
        self::assertSame('dna', $tags[0]['type']);
        self::assertNotSame('', $tags[0]['label']);
        self::assertStringStartsWith('dna-', $tags[0]['tone']);
    }

    public function test_empty_dna_values_yield_no_tags(): void
    {
        $presenter = new KeywordSemanticTagPresenter;
        self::assertSame([], $presenter->forKeyword(new Keyword, [], 7));
        self::assertSame([], $presenter->fromDnaValues(['', '  ']));
    }

    public function test_semantic_presenter_has_no_retired_classification_dependency(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Support/KeywordIntelligence/KeywordSemanticTagPresenter.php');
        self::assertStringNotContainsString('SeoKeywordClassification', $src);
        self::assertStringNotContainsString('KeywordClusterQuery', $src);
        self::assertStringNotContainsString('KeywordDnaService', $src);
        self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src);
        self::assertStringContainsString('fromDnaValues', $src);
    }

    public function test_item_presenter_passes_dna_values_into_semantic_tags(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Support/KeywordIntelligence/KeywordItemPresenter.php');
        self::assertStringContainsString('KeywordSemanticTagPresenter', $src);
        self::assertStringContainsString('semanticTags->forKeyword', $src);
        self::assertStringContainsString("'semantic_tags' => \$semanticTags", $src);
        self::assertStringNotContainsString('unset($dnaValues', $src);
        self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src);
        self::assertStringNotContainsString('KeywordDnaService', $src);
        self::assertStringNotContainsString('KeywordClusterQuery', $src);
    }

    public function test_keyword_item_blade_passes_dna_values_and_renders_semantic_block(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/keyword-item.blade.php');
        self::assertStringContainsString('dnaValues: $dnaValues', $blade);
        self::assertStringContainsString('keyword-item__semantic', $blade);
        self::assertStringContainsString('semantic_tags', $blade);
        self::assertStringContainsString('semantic-tag--', $blade);
    }

    public function test_detail_query_dna_scoped_to_site_topic_keyword(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicDetailQuery.php');
        self::assertStringContainsString('function dnaDisplayByKeyword(int $siteId, int $topicId, array $keywordIds)', $src);
        self::assertStringContainsString("where('site_id', \$siteId)", $src);
        self::assertStringContainsString("where('topic_id', \$topicId)", $src);
        self::assertStringContainsString("whereIn('keyword_id', \$keywordIds)", $src);
        self::assertStringContainsString('SeoTopicKeywordDna', $src);
        self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src);
        self::assertStringNotContainsString('KeywordDnaService', $src);
    }

    public function test_detail_page_wires_dna_map_from_topic_detail_query(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php');
        self::assertStringContainsString('dnaDisplayByKeyword', $src);
        self::assertStringContainsString('getKeywordDnaMap', $src);
        self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src);
    }

    public function test_dictionary_context_without_dna_stays_empty_semantic(): void
    {
        // Contract: omitting dnaValues (null) must not invent DNA from retired services.
        $presenterSrc = (string) file_get_contents(dirname(__DIR__, 3).'/src/Support/KeywordIntelligence/KeywordItemPresenter.php');
        self::assertStringContainsString('is_array($dnaValues) ? $dnaValues : []', $presenterSrc);
        self::assertStringNotContainsString('dnaService', $presenterSrc);
    }

    public function test_recluster_job_is_unique_per_site(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Jobs/ReclusterSiteTopicsJob.php');
        self::assertStringContainsString('ShouldBeUnique', $src);
        self::assertStringContainsString('WithoutOverlapping', $src);
        self::assertStringContainsString('topic-core-recluster:', $src);
        self::assertStringContainsString('uniqueId', $src);
    }
}
